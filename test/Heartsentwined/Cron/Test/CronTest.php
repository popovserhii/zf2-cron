<?php
namespace Heartsentwined\Cron\Test;

use Heartsentwined\Cron\Entity;
use Heartsentwined\Cron\Repository;
use Heartsentwined\Cron\Service\Cron;
use Heartsentwined\Cron\Service\Registry;
use Heartsentwined\Phpunit\Testcase\Doctrine as DoctrineTestcase;

class CronTest extends DoctrineTestcase
{
    public function setUp()
    {
        $this
            ->setBootstrap(__DIR__ . '/../../../../bootstrap.php')
            ->setEmAlias('doctrine.entitymanager.orm_default')
            ->setTmpDir('tmp');
        parent::setUp();

        $this->cron = $this->sm->get('cron')
            ->setEm($this->em);
    }

    public function tearDown()
    {
        Registry::destroy();
        unset($this->cron);
        parent::tearDown();
    }

    public function getDummy()
    {
        $dummy = $this->getMockBuilder('Heartsentwined\\Cron\\Service\\Cron')
            ->disableOriginalConstructor()
            ->getMock();
        return $dummy;
    }

    public function getJob($status, $scheduleTimestamp)
    {
        $now            = \DateTime::createFromFormat('U', time());
        $scheduleTime   = \DateTime::createFromFormat('U', $scheduleTimestamp);

        $job = new Entity\Job;
        $this->em->persist($job);
        $job
            ->setCode('time')
            ->setStatus($status)
            ->setCreateTime($now)
            ->setScheduleTime($scheduleTime);
        $this->em->flush();

        return $job;
    }
    public function testRun()
    {
        $cron = $this->getMock(
            'Heartsentwined\\Cron\\Service\\Cron',
            array('schedule', 'process', 'cleanup'));

        $cron
            ->expects($this->once())
            ->method('schedule')
            ->will($this->returnSelf());
        $cron
            ->expects($this->once())
            ->method('process')
            ->will($this->returnSelf());
        $cron
            ->expects($this->once())
            ->method('cleanup')
            ->will($this->returnSelf());

        $cron->run();
    }

    public function testGetPending()
    {
        $jobPastPending =
            $this->getJob(Repository\Job::STATUS_PENDING, time()-100);
        $jobFuturePending =
            $this->getJob(Repository\Job::STATUS_PENDING, time()+100);

        $this->getJob(Repository\Job::STATUS_SUCCESS, time()-100);
        $this->getJob(Repository\Job::STATUS_RUNNING, time()-100);
        $this->getJob(Repository\Job::STATUS_MISSED, time()-100);
        $this->getJob(Repository\Job::STATUS_ERROR, time()-100);
        $this->getJob(Repository\Job::STATUS_SUCCESS, time()+100);
        $this->getJob(Repository\Job::STATUS_RUNNING, time()+100);
        $this->getJob(Repository\Job::STATUS_MISSED, time()+100);
        $this->getJob(Repository\Job::STATUS_ERROR, time()+100);

        $pending = array();
        foreach ($this->cron->getPending() as $job) {
            $pending[] = $job->getId();
        }

        $this->assertSame(
            array(
                $jobPastPending->getId(),
                $jobFuturePending->getId(),
            ),
            $pending
        );
    }

    public function testProcess()
    {
        // only past + pending should run

        $job = $this->getJob(Repository\Job::STATUS_PENDING, time()-100);
        $cron = $this->getMock(
            'Heartsentwined\\Cron\\Service\\Cron',
            array('getPending'))
            ->setEm($this->em);
        $cron
            ->expects($this->any())
            ->method('getPending')
            ->will($this->returnValue(array($job)));
        $dummy = $this->getDummy();
        $dummy
            ->expects($this->once())
            ->method('run');
        $cron->register('time', '* * * * *', array($dummy, 'run'), array());
        $cron->process();
        $this->assertSame(Repository\Job::STATUS_SUCCESS, $job->getStatus());
        $this->assertSame(null, $job->getErrorMsg());
        $this->assertSame(null, $job->getStackTrace());
        $this->assertNotEmpty($job->getExecuteTime());
        $this->assertNotEmpty($job->getFinishTime());

        // past + (not pending) and all future

        foreach (array(
            $this->getJob(Repository\Job::STATUS_SUCCESS, time()-100),
            $this->getJob(Repository\Job::STATUS_RUNNING, time()-100),
            $this->getJob(Repository\Job::STATUS_MISSED, time()-100),
            $this->getJob(Repository\Job::STATUS_ERROR, time()-100),
            $this->getJob(Repository\Job::STATUS_PENDING, time()+100),
            $this->getJob(Repository\Job::STATUS_SUCCESS, time()+100),
            $this->getJob(Repository\Job::STATUS_RUNNING, time()+100),
            $this->getJob(Repository\Job::STATUS_MISSED, time()+100),
            $this->getJob(Repository\Job::STATUS_ERROR, time()+100),
        ) as $job) {
            $prevStatus = $job->getStatus();
            $cron = $this->getMock(
                'Heartsentwined\\Cron\\Service\\Cron',
                array('getPending'))
                ->setEm($this->em);
            $cron
                ->expects($this->any())
                ->method('getPending')
                ->will($this->returnValue(array($job)));
            $dummy = $this->getDummy();
            $dummy
                ->expects($this->never())
                ->method('run');
            $cron->register('time', '* * * * *', array($dummy, 'run'), array());
            $cron->process();
            $this->assertSame($prevStatus, $job->getStatus());
            $this->assertSame(null, $job->getErrorMsg());
            $this->assertSame(null, $job->getStackTrace());
            $this->assertNull($job->getExecuteTime());
            $this->assertNull($job->getFinishTime());
        }

        // cron job throwing exceptions

        $job = $this->getJob(Repository\Job::STATUS_PENDING, time()-100);
        $cron = $this->getMock(
            'Heartsentwined\\Cron\\Service\\Cron',
            array('getPending'))
            ->setEm($this->em);
        $cron
            ->expects($this->any())
            ->method('getPending')
            ->will($this->returnValue(array($job)));
        $dummy = $this->getDummy();
        $dummy
            ->expects($this->once())
            ->method('run')
            ->will($this->throwException(new \RuntimeException(
                'foo runtime exception'
            )));
        $cron->register('time', '* * * * *', array($dummy, 'run'), array());
        $cron->process();
        $this->assertSame(Repository\Job::STATUS_ERROR, $job->getStatus());
        $this->assertSame('foo runtime exception', $job->getErrorMsg());
        $this->assertNotEmpty($job->getStackTrace());
        $this->assertNotEmpty($job->getExecuteTime());
        $this->assertNull($job->getFinishTime());

        // too late for job

        $job = $this->getJob(Repository\Job::STATUS_PENDING, time()-100);
        $cron = $this->getMock(
            'Heartsentwined\\Cron\\Service\\Cron',
            array('getPending'))
            ->setScheduleLifetime(0)
            ->setEm($this->em);
        $cron
            ->expects($this->any())
            ->method('getPending')
            ->will($this->returnValue(array($job)));
        $dummy = $this->getDummy();
        $dummy
            ->expects($this->never())
            ->method('run');
        $cron->register('time', '* * * * *', array($dummy, 'run'), array());
        $cron->process();
        $this->assertSame(Repository\Job::STATUS_MISSED, $job->getStatus());
        $this->assertSame('too late for job', $job->getErrorMsg());
        $this->assertNotEmpty($job->getStackTrace());
        $this->assertNull($job->getExecuteTime());
        $this->assertNull($job->getFinishTime());

        // job not registered

        $job = $this->getJob(Repository\Job::STATUS_PENDING, time()-100);
        $cron = $this->getMock(
            'Heartsentwined\\Cron\\Service\\Cron',
            array('getPending'))
            ->setEm($this->em);
        $cron
            ->expects($this->any())
            ->method('getPending')
            ->will($this->returnValue(array($job)));
        $dummy = $this->getDummy();
        $dummy
            ->expects($this->never())
            ->method('run');
        Registry::destroy();
        $cron->process();
        $this->assertSame(Repository\Job::STATUS_ERROR, $job->getStatus());
        $this->assertSame('job "time" undefined in cron registry', $job->getErrorMsg());
        $this->assertNotEmpty($job->getStackTrace());
        $this->assertNull($job->getExecuteTime());
        $this->assertNull($job->getFinishTime());
    }

    public function testSchedule()
    {
        // reg a 15-min for 1hr
        $this->cron->setScheduleAhead(60);
        $dummy = $this->getDummy();
        $dummy
            ->expects($this->any())
            ->method('run');

        $this->cron->register(
            'time', '*/15 * * * *', array($dummy, 'run'), array());

        // pending job set should be empty before calling schedule
        $pending = $this->em->getRepository('Heartsentwined\Cron\Entity\Job')
            ->getPending();
        $this->assertCount(0, $pending);

        $this->cron
            ->resetPending()
            ->schedule();
        $pending = $this->em->getRepository('Heartsentwined\Cron\Entity\Job')
            ->getPending();
        $this->assertCount(4, $pending);

        // re-schedule - nothing should have changed
        $this->cron
            ->resetPending()
            ->schedule();
        $pending = $this->em->getRepository('Heartsentwined\Cron\Entity\Job')
            ->getPending();
        $this->assertCount(4, $pending);

        // extend reg period for another hour
        $this->cron
            ->setScheduleAhead(120)
            ->resetPending()
            ->schedule();
        $pending = $this->em->getRepository('Heartsentwined\Cron\Entity\Job')
            ->getPending();
        $this->assertCount(8, $pending);

        // reg another job
        $this->cron->register(
            'time2', '*/30 * * * *', array($dummy, 'run'), array());

        // pending job set should not have changed yet
        $pending = $this->em->getRepository('Heartsentwined\Cron\Entity\Job')
            ->getPending();
        $this->assertCount(8, $pending);

        // now schedule it - for 2hrs, as per changed
        $this->cron
            ->resetPending()
            ->schedule();
        $pending = $this->em->getRepository('Heartsentwined\Cron\Entity\Job')
            ->getPending();
        $this->assertCount(12, $pending);
    }
}
