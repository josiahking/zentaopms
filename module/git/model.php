<?php
/**
 * The model file of git module of ZenTaoPMS.
 *
 * @copyright   Copyright 2009-2015 青岛易软天创网络科技有限公司(QingDao Nature Easy Soft Network Technology Co,LTD, www.cnezsoft.com)
 * @license     ZPL (http://zpl.pub/page/zplv12.html)
 * @author      Chunsheng Wang <chunsheng@cnezsoft.com>
 * @package     git
 * @version     $Id$
 * @link        http://www.zentao.net
 */
?>
<?php
class gitModel extends model
{
    /**
     * The git binary client.
     * 
     * @var int   
     * @access public
     */
    public $client;

    /**
     * Repos.
     * 
     * @var array 
     * @access public
     */
    public $repos = array(); 

    /**
     * The log root.
     * 
     * @var string
     * @access public
     */
    public $logRoot = '';

    /**
     * The restart file.
     * 
     * @var string
     * @access public
     */
    public $restartFile = '';

    /**
     * The root path of a repo
     * 
     * @var string
     * @access public
     */
    public $repoRoot = '';

    /**
     * Users 
     * 
     * @var array 
     * @access public
     */
    public $users = array();

    /**
     * Construct function.
     * 
     * @access public
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->loadModel('action');
    }

    /**
     * Run. 
     * 
     * @access public
     * @return void
     */
    public function run()
    {
        $this->setRepos();
        if(empty($this->repos)) return false;

        $this->setLogRoot();
        $this->setRestartFile();

        foreach($this->repos as $repo)
        {
            $this->printLog("begin repo $repo->id");
            if(!$this->setRepo($repo)) return false;

            $savedRevision = $this->getSavedRevision();
            $this->printLog("start from revision $savedRevision");
            $logs = $this->getRepoLogs($repo, $savedRevision);
            if(!empty($logs)) {
                $this->printLog("get " . count($logs) . " logs");
                $this->printLog('begin parsing logs');
                $latestRevision = $logs[0]->revision;

                $allCommands = [];
                foreach ($logs as $log) {
                    $this->printLog("parsing log {$log->revision}");
                    if ($log->revision == $savedRevision)
                    {
                        $this->printLog("{$log->revision} alread parsed, commit it");
                        continue;
                    }

                    $this->printLog("comment is\n----------\n" . trim($log->msg) . "\n----------");

                    $scm = $this->app->loadClass('scm');
                    $objects = $scm->parseComment($log->msg, $allCommands);

                    if($objects)
                    {
                        $this->printLog('extract' .
                            ' story:' . join(' ', $objects['stories']) .
                            ' task:' . join(' ', $objects['tasks']) .
                            ' bug:'  . join(',', $objects['bugs']));

                        $this->saveAction2PMS($objects, $log, $repo->encoding);
                    }
                    else
                    {
                        $this->printLog('no objects found' . "\n");
                    }
                }

                $this->saveLastRevision($latestRevision);
                $this->printLog("save revision $latestRevision");
                $this->deleteRestartFile();
                $this->printLog("\n\nrepo ' . $repo->id . ': ' . $repo->path . ' finished");

                $this->printLog('extract commands from logs' . json_encode($allCommands));
            }

            // exe ci task from logs
            $cijobIDs = $allCommands['build']['start'];
            foreach($cijobIDs as $id)
            {
                $this->loadModel('ci')->exeJob($id);
            }

            // dealwith tag commands
            $this->printLog("dealwith tag commands");
            $savedTag = $this->getSavedTag();

            $tags = $this->getRepoTags($repo);
            if(!empty($tags)) {
                $arriveLastTag = false;
                $jobToBuild = [];
                foreach ($tags as $tag) {
                    if (!empty($savedTag) && $tag === $savedTag) // get the last build tag position
                    {
                        $arriveLastTag = true;
                        continue;
                    }

                    if (!empty($savedTag) && !$arriveLastTag) // not get
                    {
                        continue;
                    }

                    $scm = $this->app->loadClass('scm');
                    $scm->parseTag($tag, $jobToBuild);
                }

                $this->saveLastTag($tags[count($tags) - 1]);

                $this->printLog('extract tasks to build: ' . json_encode($jobToBuild));

                foreach ($jobToBuild as $id) {
                    $this->loadModel('ci')->exeJob($id);
                }
            }
        }
    }

    /**
     * Set the log root.
     * 
     * @access public
     * @return void
     */
    public function setLogRoot()
    {
        $this->logRoot = $this->app->getTmpRoot() . 'git/';
        if(!is_dir($this->logRoot)) mkdir($this->logRoot);
    }

    /**
     * Set the restart file.
     * 
     * @access public
     * @return void
     */
    public function setRestartFile()
    {
        $this->restartFile = dirname(__FILE__) . '/restart';
    }

    /**
     * Delete the restart file.
     * 
     * @access public
     * @return void
     */
    public function deleteRestartFile()
    {
        if(is_file($this->restartFile)) unlink($this->restartFile);
    }

    /**
     * Set the repos.
     * 
     * @access public
     * @return bool
     */
    public function setRepos()
    {
        $repo = $this->loadModel('repo');
        $repoObjs = $repo->listForSync("SCM='Git'");

        $gitRepos = [];
        $paths = [];
        // ignore same repo in config.php
        foreach($repoObjs as $repoInDb)
        {
            if(strtolower($repoInDb->SCM) === 'git' && !in_array($repoInDb->path, $gitRepos)) {
                $gitRepos[] = (object)array('id'=>$repoInDb->id, 'path' => $repoInDb->path,
                    'encoding' => $repoInDb->encoding, 'client' => $repoInDb->client);
                $paths[] = $repoInDb->path;
            }
        }

        if(!$gitRepos)
        {
            echo "You must set one git repo.\n";
            return false;
        }

        $this->repos = $gitRepos;
        return true;
    }

    /**
     * Get repos.
     * 
     * @access public
     * @return array
     */
    public function getRepos()
    {
        $repos = array();
        if(!$this->config->git->repos) return $repos;

        foreach($this->config->git->repos as $repo)
        {
            if(empty($repo['path'])) continue;
            $repos[] = $repo['path'];
        }
        return $repos;
    }

    /**
     * Set repo.
     * 
     * @param  object    $repo 
     * @access public
     * @return bool
     */
    public function setRepo($repo)
    {
        $this->setClient($repo);
        if(empty($this->client)) return false;

        $this->setLogFile($repo->id);
        $this->setTagFile($repo->id);
        $this->setRepoRoot($repo);
        return true;
    }

    /**
     * Set the git binary client of a repo.
     *
     * @param  object    $repo
     * @access public
     * @return bool
     */

    public function setClient($repo)
    {
        $this->client = $repo->client;
        return true;
    }

    /**
     * Set the log file of a repo.
     *
     * @param  string    $repoId
     * @access public
     * @return void
     */
    public function setLogFile($repoId)
    {
        $this->logFile = $this->logRoot . $repoId . '.log';
    }

    /**
     * Set the tag file of a repo.
     *
     * @param  string    $repoId
     * @access public
     * @return void
     */
    public function setTagFile($repoId)
    {
        $this->tagFile = $this->logRoot . $repoId . '.tag';
    }

    /**
     * set the root path of a repo.
     * 
     * @param  object    $repo 
     * @access public
     * @return void 
     */
    public function setRepoRoot($repo)
    {
        $this->repoRoot = $repo->path;
    }

    /**
     * get tags histories for repo.
     *
     * @param  object    $repo
     * @access public
     * @return void
     */
    public function getRepoTags($repo)
    {
        $parsedTags = array();

        /* The git tag command. */
        chdir($this->repoRoot);
        exec("{$this->client} config core.quotepath false");

        $cmd = "$this->client for-each-ref --sort=taggerdate | grep refs/tags | grep -v commit";
        exec($cmd, $list, $return);
        foreach($list as $line)
        {
            $arr = explode('refs/tags/', $line);
            $parsedTags[] = $arr[count($arr) - 1];
        }

        return $parsedTags;
    }

    /**
     * Get repo logs.
     * 
     * @param  object  $repo 
     * @param  int     $fromRevision 
     * @access public
     * @return array
     */
    public function getRepoLogs($repo, $fromRevision)
    {
        $parsedLogs = array();

        /* The git log command. */
        chdir($this->repoRoot);
        exec("{$this->client} config core.quotepath false");
        if($fromRevision)
        {
            $cmd = "$this->client log --stat=1024 --stat-name-width=1000 --name-status $fromRevision..HEAD";
        }
        else
        {
            $cmd = "$this->client log --stat=1024 --stat-name-width=1000 --name-status";
        }
        exec($cmd, $list, $return);

        if(!$list and $return) 
        {
            echo "Some error occers: \nThe command is $cmd\n";
            return false;
        }
        if(!$list and !$return) return array();

        /* Process logs. */
        $logs = array();
        $i    = 0;

        foreach($list as $line) 
        {
            if(strpos($line, 'commit ') === 0) $i++;
            $logs[$i][] = $line;
        }

        foreach($logs as $log) $parsedLogs[] = $this->convertLog($log);
        return $parsedLogs;
    }

    /**
     * Convert log from xml format to object.
     * 
     * @param  object    $log 
     * @access public
     * @return object
     */
    public function convertLog($log)
    {
        list($hash, $account, $date) = $log;

        $account = preg_replace('/^Author:/', '', $account);
        $account = trim(preg_replace('/<[a-zA-Z0-9_\-\.]+@[a-zA-Z0-9_\-\.]+>/', '', $account));
        $date    = trim(preg_replace('/^Date:/', '', $date));

        $count   = count($log);
        $comment = '';
        $files   = array();
        for($i = 3; $i < $count; $i++)
        {
            $line = $log[$i];
            if(preg_match('/^\s{2,}/', $line))
            {
                $comment .= $line;
            }
            elseif(strpos($line, "\t") !== false)
            {
                list($action, $entry) = explode("\t", $line);
                $entry = '/' . trim($entry);
                $files[$action][] = $entry;
            }
        }
        $parsedLog = new stdClass();
        $parsedLog->author    = $account;
        $parsedLog->revision  = trim(preg_replace('/^commit/', '', $hash));
        $parsedLog->msg       = trim($comment);
        $parsedLog->date      = date('Y-m-d H:i:s', strtotime($date));
        $parsedLog->files     = $files;

        return $parsedLog;
    }

    /**
     * Diff a url.
     * 
     * @param  string $path
     * @param  int    $revision 
     * @access public
     * @return string|bool
     */
    public function diff($path, $revision)
    {
        $repo = $this->getRepoByURL($path);
        if(!$repo) return false;

        $this->setClient($repo);
        if(empty($this->client)) return false;
        putenv('LC_CTYPE=en_US.UTF-8');

        chdir($repo->path);
        exec("{$this->client} config core.quotepath false");
        $subPath = substr($path, strlen($repo->path));
        if($subPath{0} == '/' or $subPath{0} == '\\') $subPath = substr($subPath, 1);

        $encodings = explode(',', $this->config->git->encodings);
        foreach($encodings as $encoding)
        {
            $encoding = trim($encoding);
            if($encoding == 'utf-8') continue;
            $subPath = helper::convertEncoding($subPath, 'utf-8', $encoding);
            if($subPath) break;
        }

        exec("$this->client rev-list -n 2 $revision -- $subPath", $lists);
        if(count($lists) == 2) list($nowRevision, $preRevision) = $lists;
        $cmd = "$this->client diff $preRevision $nowRevision -- $subPath 2>&1";
        $diff = `$cmd`;

        $encoding = isset($repo->encoding) ? $repo->encoding : 'utf-8';
        if($encoding and $encoding != 'utf-8') $diff = helper::convertEncoding($diff, $encoding);

        return $diff;
    }

    /**
     * Cat a url.
     * 
     * @param  string $path
     * @param  int    $revision 
     * @access public
     * @return string|bool
     */
    public function cat($path, $revision)
    {
        $repo = $this->getRepoByURL($path);
        if(!$repo) return false;

        $this->setClient($repo);
        if(empty($this->client)) return false;

        putenv('LC_CTYPE=en_US.UTF-8');

        $subPath = substr($path, strlen($repo->path));
        if($subPath{0} == '/' or $subPath{0} == '\\') $subPath = substr($subPath, 1);

        $encodings = explode(',', $this->config->git->encodings);
        foreach($encodings as $encoding)
        {
            $encoding = trim($encoding);
            if($encoding == 'utf-8') continue;
            $subPath = helper::convertEncoding($subPath, 'utf-8', $encoding);
            if($subPath) break;
        }

        chdir($repo->path);
        exec("{$this->client} config core.quotepath false");
        $cmd  = "$this->client show $revision:$subPath 2>&1";
        $code = `$cmd`;

        $encoding = isset($repo->encoding) ? $repo->encoding : 'utf-8';
        if($encoding and $encoding != 'utf-8') $code = helper::convertEncoding($code, $encoding);

        return $code;
    }

    /**
     * Get repo by url.
     * 
     * @param  string    $url 
     * @access public
     * @return object|bool
     */
    public function getRepoByURL($url)
    {
        foreach($this->config->git->repos as $repo)
        {
            if(empty($repo['path'])) continue;
            if(strpos($url, $repo['path']) !== false) return (object)$repo;
        }
        return false;
    }

    /**
     * Save action to pms.
     * 
     * @param  array    $objects 
     * @param  object   $log 
     * @param  string   $repoRoot 
     * @access public
     * @return void
     */
    public function saveAction2PMS($objects, $log, $repoRoot = '', $encodings = 'utf-8')
    {
        $action = new stdclass();
        $action->actor   = $log->author;
        $action->action  = 'gitcommited';
        $action->date    = $log->date;

        $scm = $this->app->loadClass('scm');
        $action->comment = htmlspecialchars($scm->iconvComment($log->msg, $encodings));
        $action->extra   = substr($log->revision, 0, 10);

        $changes = $this->createActionChanges($log, $repoRoot);

        if($objects['stories'])
        {
            $products = $this->getStoryProducts($objects['stories']);
            foreach($objects['stories'] as $storyID)
            {
                $storyID = (int)$storyID;
                if(!isset($products[$storyID])) continue;

                $action->objectType = 'story';
                $action->objectID   = $storyID;
                $action->product    = $products[$storyID];
                $action->project    = 0;

                $this->saveRecord($action, $changes);
            }
        }

        if($objects['tasks'])
        {
            $productsAndProjects = $this->getTaskProductsAndProjects($objects['tasks']);
            foreach($objects['tasks'] as $taskID)
            {
                $taskID = (int)$taskID;
                if(!isset($productsAndProjects[$taskID])) continue;

                $action->objectType = 'task';
                $action->objectID   = $taskID;
                $action->product    = $productsAndProjects[$taskID]['product'];
                $action->project    = $productsAndProjects[$taskID]['project'];

                $this->saveRecord($action, $changes);
            }
        }

        if($objects['bugs'])
        {
            $productsAndProjects = $this->getBugProductsAndProjects($objects['bugs']);

            foreach($objects['bugs'] as $bugID)
            {
                $bugID = (int)$bugID;
                if(!isset($productsAndProjects[$bugID])) continue;

                $action->objectType = 'bug';
                $action->objectID   = $bugID;
                $action->product    = $productsAndProjects[$bugID]->product;
                $action->project    = $productsAndProjects[$bugID]->project;

                $this->saveRecord($action, $changes);
            }
        }
    }

    /**
     * Save an action to pms.
     * 
     * @param  object $action
     * @param  object $log
     * @access public
     * @return bool
     */
    public function saveRecord($action, $changes)
    {
        $record = $this->dao->select('*')->from(TABLE_ACTION)
            ->where('objectType')->eq($action->objectType)
            ->andWhere('objectID')->eq($action->objectID)
            ->andWhere('extra')->eq($action->extra)
            ->andWhere('action')->eq('gitcommited')
            ->fetch();
        if($record)
        {
            $this->dao->update(TABLE_ACTION)->data($action)->where('id')->eq($record->id)->exec();
            if($changes)
            {
                $historyID = $this->dao->findByAction($record->id)->from(TABLE_HISTORY)->fetch('id');
                if($historyID)
                {
                    $this->dao->update(TABLE_HISTORY)->data($changes)->where('id')->eq($historyID)->exec();
                }
                else
                {
                    $this->action->logHistory($record->id, array($changes));
                }
            }
        }
        else
        {
            $this->dao->insert(TABLE_ACTION)->data($action)->autoCheck()->exec();
            if($changes)
            {
                $actionID = $this->dao->lastInsertID();
                $this->action->logHistory($actionID, array($changes));
            }
        }
    }

    /**
     * Create changes for action from a log.
     * 
     * @param  object    $log 
     * @param  string    $repoRoot 
     * @access public
     * @return array
     */
    public function createActionChanges($log, $repoRoot)
    {
        if(!$log->files) return array();
        $diff = '';

        $oldSelf = $this->server->PHP_SELF;
        $this->server->set('PHP_SELF', $this->config->webRoot, '', false, true);

        if(!$repoRoot) $repoRoot = $this->repoRoot;

        foreach($log->files as $action => $actionFiles)
        {
            foreach($actionFiles as $file)
            {
                $catLink  = trim(html::a($this->buildURL('cat',  $repoRoot . $file, $log->revision), 'view', '', "class='iframe' data-width='960'"));
                $diffLink = trim(html::a($this->buildURL('diff', $repoRoot . $file, $log->revision), 'diff', '', "class='iframe' data-width='960'"));
                $diff .= $action . " " . $file . " $catLink ";
                $diff .= $action == 'M' ? "$diffLink\n" : "\n" ;
            }
        }
        $changes = new stdclass();
        $changes->field = 'git';
        $changes->old   = '';
        $changes->new   = '';
        $changes->diff  = trim($diff);

        $this->server->set('PHP_SELF', $oldSelf);
        return (array)$changes;
    }

    /**
     * Get products of stories.
     * 
     * @param  array    $stories 
     * @access public
     * @return array
     */
    public function getStoryProducts($stories)
    {
        return $this->dao->select('id, product')->from(TABLE_STORY)->where('id')->in($stories)->fetchPairs();
    }

    /**
     * Get products and projects of tasks.
     * 
     * @param  array    $tasks 
     * @access public
     * @return array
     */
    public function getTaskProductsAndProjects($tasks)
    {
        $records = array();
        $products = $this->dao->select('t1.id, t2.product')
            ->from(TABLE_TASK)->alias('t1')
            ->leftJoin(TABLE_STORY)->alias('t2')->on('t1.story = t2.id')
            ->where('t1.id')->in($tasks)->fetchPairs();

        $projects = $this->dao->select('id, project')->from(TABLE_TASK)->where('id')->in($tasks)->fetchPairs();

        foreach($projects as $taskID => $projectID)
        {
            $record = array();
            $record['project'] = $projectID;
            $record['product'] = isset($products[$taskID]) ? $products[$taskID] : 0;
            $records[$taskID] = $record;
        }
        return $records;
    }

    /**
     * Get products and projects of bugs.
     * 
     * @param  array    $bugs 
     * @access public
     * @return array
     */
    public function getBugProductsAndProjects($bugs)
    {
        return $this->dao->select('id, project, product')->from(TABLE_BUG)->where('id')->in($bugs)->fetchAll('id');
    }

    /**
     * Get the saved revision.
     * 
     * @access public
     * @return int
     */
    public function getSavedRevision()
    {
        if(!file_exists($this->logFile)) return 0;
        if(file_exists($this->restartFile)) return 0;
        return trim(file_get_contents($this->logFile));
    }

    /**
     * Save the last revision.
     * 
     * @param  int    $revision 
     * @access public
     * @return void
     */
    public function saveLastRevision($revision)
    {
        $ret = file_put_contents($this->logFile, $revision);
    }

    /**
     * Get the saved tag.
     *
     * @access public
     * @return int
     */
    public function getSavedTag()
    {
        if(!file_exists($this->tagFile)) return 0;
        if(file_exists($this->restartFile)) return 0;
        return trim(file_get_contents($this->tagFile));
    }

    /**
     * Save the last revision.
     *
     * @param  int    $tag
     * @access public
     * @return void
     */
    public function saveLastTag($tag)
    {
        $ret = file_put_contents($this->tagFile, $tag);
    }

    /**
     * Pring log.
     * 
     * @param  sting    $log 
     * @access public
     * @return void
     */
    public function printLog($log)
    {
        echo helper::now() . " $log\n";
    }


    /**
     * Build URL.
     * 
     * @param  string $methodName 
     * @param  string $url 
     * @param  int    $revision 
     * @access public
     * @return string
     */
    public function buildURL($methodName, $url, $revision)
    {
        $buildedURL  = helper::createLink('git', $methodName, "path=&revision=$revision", 'html');
        $buildedURL .= strpos($buildedURL, '?') === false ? '?' : '&';
        $buildedURL .= 'repoUrl=' . helper::safe64Encode($url);
        return $buildedURL;
    }
}
