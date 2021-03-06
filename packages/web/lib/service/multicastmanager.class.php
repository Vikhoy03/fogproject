<?php
class MulticastManager extends FOGService
{
    public static $sleeptime = 'MULTICASTSLEEPTIME';
    protected $altLog;
    public function __construct()
    {
        parent::__construct();
        list($dev, $log, $zzz) = self::getSubObjectIDs('Service', array('name'=>array('MULTICASTDEVICEOUTPUT', 'MULTICASTLOGFILENAME', self::$sleeptime)), 'value', false, 'AND', 'name', false, '');
        static::$log = sprintf('%s%s', self::$logpath ? self::$logpath : '/opt/fog/log/', $log ? $log : 'multicast.log');
        if (file_exists(static::$log)) {
            unlink(static::$log);
        }
        static::$dev = $dev ? $dev : '/dev/tty2';
        static::$zzz = ($zzz ? $zzz : 10);
    }
    private function isMCTaskNew($KnownTasks, $id)
    {
        foreach ((array)$KnownTasks as $i => &$Known) {
            $output[] = $Known->getID();
        }
        unset($Known);
        return !in_array($id, (array)$output);
    }
    private function getMCExistingTask($KnownTasks, $id)
    {
        foreach ((array)$KnownTasks as $i => &$Known) {
            if ($Known->getID() == $id) {
                return $Known;
            }
        }
        unset($Known);
    }
    private function removeFromKnownList($KnownTasks, $id)
    {
        $new = array();
        foreach ((array)$KnownTasks as $i => $Known) {
            if ($Known->getID() != $id) {
                $new[] = $Known;
            }
        }
        unset($Known);
        return array_filter((array)$new);
    }
    private function getMCTasksNotInDB($KnownTasks, $AllTasks)
    {
        $ret = $allIDs = array();
        foreach ((array)$AllTasks as $i => &$AllTask) {
            if ($AllTask && $AllTask->getID()) {
                $allIDs[] = $AllTask->getID();
            }
            unset($AllTask);
        }
        foreach ((array)$KnownTasks as $i => &$Known) {
            if (!in_array($Known->getID(), (array)$allIDs)) {
                $ret[] = $Known;
            }
            unset($Known);
        }
        return array_filter((array)$ret);
    }
    private function serviceLoop()
    {
        while (true) {
            try {
                $StorageNodes = $this->checkIfNodeMaster();
                foreach ((array)$StorageNodes as &$StorageNode) {
                    if (!$StorageNode->isValid()) {
                        continue;
                    }
                    $myroot = $StorageNode->get('path');
                    $RMTasks = array();
                    foreach ((array)$KnownTasks as &$mcTask) {
                        $activeCount = self::getClass('TaskManager')->count(array('id'=>$mcTask->getTaskIDs(), 'stateID'=>array_merge($this->getQueuedStates(), (array)$this->getProgressState())));
                        if ($activeCount < 1 && ($mcTask->getSessClients() == 0 || in_array(self::getClass('MulticastSessions', $mcTask->getID())->get('stateID'), array($this->getCompleteState(), $this->getCancelledState())))) {
                            $RMTasks[] =& $mcTask;
                        }
                        unset($mcTask);
                    }
                    $jobcancelled = false;
                    $RMCount = count($RMTasks);
                    if ($RMCount > 0) {
                        self::outall(sprintf(" | %d task%s to be cleaned", $RMCount, $RMCount != 1 ? 's' : ''));
                        self::outall(sprintf(" | Cleaning %s task(s) removed from FOG Database.", $RMCount));
                        foreach ((array)$RMTasks as &$RMTask) {
                            $RTask = $this->getMCExistingTask($KnownTasks, $RMTask->getID());
                            self::outall(sprintf(" | Cleaning Task (%s) %s", $RTask->getID(), $RTask->getName()));
                            $taskIDs = $RMTask->getTaskIDs();
                            $inTaskIDs = self::getSubObjectIDs('Task', array('id'=>$taskIDs, 'stateID'=>$this->getCancelledState()));
                            if (self::getClass('MulticastSessions', $RMTask->getID())->get('stateID') == $this->getCancelledState()) {
                                $jobcancelled = true;
                            }
                            if (count($inTaskIDs) > 0) {
                                $jobcancelled = true;
                            }
                            if ($jobcancelled || self::getClass('MulticastSessions', $RTask->getID())->get('stateID') == $this->getCancelledState()) {
                                self::getClass('TaskManager')->update(array('id'=>$taskIDs), '', array('stateID'=>$this->getCancelledState()));
                                self::getClass('MulticastSessions', $RTask->getID())->set('stateID', $this->getCancelledState())->set('name', '')->save();
                                self::outall(sprintf(" | Task (%s) %s has been cancelled.", $RMTask->getID(), $RMTask->getName()));
                            } else {
                                self::getClass('TaskManager')->update(array('id'=>$taskIDs), '', array('stateID'=>$this->getCompleteState()));
                                self::getClass('MulticastSessions', $RMTask->getID())->set('stateID', $this->getCompleteState())->save();
                                self::outall(sprintf(" | Task (%s) %s has been completed.", $RMTask->getID(), $RMTask->getName()));
                            }
                            $RTask->killTask();
                            $KnownTasks = $this->removeFromKnownList($KnownTasks, $RTask->getID());
                            self::getClass('MulticastSessionsAssociationManager')->destroy(array('msID'=>$RTask->getID()));
                            unset($RMTask, $RTask);
                        }
                    }
                    $allTasks = self::getClass('MulticastTask')->getAllMulticastTasks($myroot, $StorageNode->get('id'));
                    $taskCount = count($allTasks);
                    if ($taskCount < 1 || !$taskCount) {
                        self::outall(sprintf(' * %s!', _('No tasks found')));
                    }
                    foreach ((array)$allTasks as &$curTask) {
                        if ($this->isMCTaskNew($KnownTasks, $curTask->getID())) {
                            self::outall(sprintf(" | Task (%s) %s is new!", $curTask->getID(), $curTask->getName()));
                            if (!file_exists($curTask->getImagePath())) {
                                self::outall(sprintf(" Task (%s) %s failed to execute, image file:%s not found on this node!", $curTask->getID(), $curTask->getName(), $curTask->getImagePath()));
                                continue;
                            }
                            if (!$curTask->getClientCount()) {
                                self::outall(sprintf(" Task (%s) %s failed to execute, no clients are included!", $curTask->getID(), $curTask->getName()));
                                continue;
                            }
                            if (!is_numeric($curTask->getPortBase()) || !($curTask->getPortBase() % 2 == 0)) {
                                self::outall(sprintf(" Task (%s) %s failed to execute, port must be even and numeric.", $curTask->getID(), $curTask->getName()));
                                continue;
                            }
                            if (!$curTask->startTask()) {
                                self::outall(sprintf(" | Task (%s) %s failed to start!", $curTask->getID(), $curTask->getName()));
                                self::outall(sprintf(" | * Don't panic, check all your settings!"));
                                self::outall(sprintf(" |       even if the interface is incorrect the task won't start."));
                                self::outall(sprintf(" |       If all else fails run the following command and see what it says:"));
                                self::outall(sprintf(" |  %s", $curTask->getCMD()));
                                $curTask->killTask();
                                self::outall(" Task (%s) %s has been cleaned.");
                                continue;
                            }
                            self::outall(sprintf(" | Task (%s) %s has been cleaned.", $curTask->getID(), $curTask->getName()));
                            self::outall(sprintf(" | Task (%s) %s image file found.", $curTask->getID(), $curTask->getImagePath()));
                            self::outall(sprintf(" | Task (%s) %s client(s) found or to receive.", $curTask->getID(), $curTask->getClientCount()));
                            self::outall(sprintf(" | Task (%s) %s sending on base port: %s", $curTask->getID(), $curTask->getName(), $curTask->getPortBase()));
                            self::outall(sprintf(" | CMD: %s", $curTask->getCMD()));
                            self::outall(sprintf(" | Task (%s) %s has started.", $curTask->getID(), $curTask->getName()));
                            $KnownTasks[] = $curTask;
                        } else {
                            $jobcancelled = $jobcompleted = false;
                            $runningTask = $this->getMCExistingTask($KnownTasks, $curTask->getID());
                            $taskIDs = $curTask->getTaskIDs();
                            $inTaskCancelledIDs = self::getSubObjectIDs('Task', array('id'=>$taskIDs, 'stateID'=>$this->getCancelledState()));
                            $inTaskIDs = self::getSubObjectIDs('Task', array('id'=>$taskIDs, 'stateID'=>$this->getCompleteState()));
                            if (count($inTaskCancelledIDs) > 0) {
                                $jobcancelled = true;
                            }
                            if (count($inTaskIDs) > 0) {
                                $jobcompleted = true;
                            }
                            if (self::getClass('MulticastSessions', $runningTask->getID())->get('stateID') == $this->getCancelledState()) {
                                $jobcancelled = true;
                            }
                            if ($runningTask->isNamedSession && $runningTask->getSessClients() == 0) {
                                $jobcompleted = true;
                            }
                            if (!$jobcompleted && !$jobcancelled && $runningTask->isRunning($runningTask->procRef)) {
                                self::outall(sprintf(' | Task (%s) %s is already running PID %s', $runningTask->getID(), $runningTask->getName(), $runningTask->getPID($runningTask->procRef)));
                                $runningTask->updateStats();
                            } else {
                                self::outall(sprintf(" | Task (%s) %s is no longer running.", $runningTask->getID(), $runningTask->getName()));
                                if ($jobcancelled || self::getClass('MulticastSessions', $runningTask->getID())->get('stateID') == $this->getCancelledState()) {
                                    $KnownTasks = $this->removeFromKnownList($KnownTasks, $runningTask->getID());
                                    if (!$runningTask->killTask()) {
                                        self::outall(sprintf(" Failed to kill task (%s) %s PID:%s!", $runningTask->getID(), $runningTask->getName(), $runningTask->getPID($runningTask->procRef)));
                                        continue;
                                    }
                                    self::outall(sprintf(" | Task (%s) %s has been cancelled.", $runningTask->getID(), $runningTask->getName()));
                                } else {
                                    self::getClass('MulticastSessions', $runningTask->getID())->set('clients', 0)->set('completetime', self::niceDate()->format('Y-m-d H:i:s'))->set('name', '')->set('stateID', $this->getCompleteState())->save();
                                    $KnownTasks = $this->removeFromKnownList($KnownTasks, $runningTask->getID());
                                    self::outall(sprintf(" | Task (%s) %s has been completed.", $runningTask->getID(), $runningTask->getName()));
                                }
                            }
                        }
                        unset($curTask);
                    }
                    unset($StorageNode);
                }
                unset($StorageNodes);
            } catch (Exception $e) {
                self::outall($e->getMessage());
            }
            self::out(' +---------------------------------------------------------', static::$dev);
            $tmpTime = self::getSetting(self::$sleeptime);
            if (static::$zzz != $tmpTime) {
                static::$zzz = $tmpTime ? $tmpTime : 10;
                self::outall(sprintf(" | Sleep time has changed to %s seconds", static::$zzz));
            }
            sleep(static::$zzz);
            $oldCount = $taskCount;
        }
    }
    public function serviceRun()
    {
        self::out(' ', static::$dev);
        self::out(' +---------------------------------------------------------', static::$dev);
        self::serviceLoop();
    }
}
