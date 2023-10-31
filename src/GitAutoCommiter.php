<?php
namespace dynoser\autoload;

use CzProject\GitPhp\Git;

class GitAutoCommiter
{
    private $repository;
    private $gitObj;
    private $sitePath;
    public $mainBranch = 'main';

    public function __construct($sitePath)
    {
        $this->sitePath = \realpath($sitePath);
        if (!$this->sitePath) {
            throw new \Exception("Not found sitePath=$sitePath");
        }
        $this->sitePath = \strtr($this->sitePath, '\\', '/');
    }
    
    public function setRepository()
    {
        if ($this->repository) {
            return;
        }
        $sitePath = $this->sitePath;

        $this->gitObj = new Git();

        try {
            $chkGitPath    = $this->sitePath . '/.git';
            $gitIgnoreFile = $this->sitePath . '/.gitignore';
            $needInitCommit = !\file_exists($chkGitPath);
            if ($needInitCommit) {
                $repo = $this->gitObj->init($sitePath);
                $repo->execute('checkout', '-b', $this->mainBranch);
                // auto-create .gitignore if not exist
                if (!\is_file($gitIgnoreFile)) {
                    \file_put_contents($gitIgnoreFile,
<<<GITIGNORE
/storage/
/nbproject/
GITIGNORE
);
                }
                // check default user
                try {
                    $userName = $repo->execute('config', 'user.name');
                } catch (\Throwable $ex) {
                    $userName = null;
                }
                if (!$userName) {
                    // set git user.name and user.email if need
                    $gitUserName  = \defined('GIT_USER_NAME')  ? \constant('GIT_USER_NAME')  : 'autoloader';
                    $gitUserEmail = \defined('GIT_USER_EMAIL') ? \constant('GIT_USER_EMAIL') : 'auto@commit.com';
                    $output = $repo->execute('config', 'user.name', "\"$gitUserName\"");
                    $output = $repo->execute('config', 'user.email', "\"$gitUserEmail\"");
                }
                
                
            } else {
                $repo = $this->gitObj->open($sitePath);
                $branches = $repo->getBranches();            
                if ($branches) {
                    $currentBranch = $repo->getCurrentBranchName();
                } else {
                    $needInitCommit = true;
                    $currentBranch = '';
                }
                if ($currentBranch !== $this->mainBranch) {
                    $repo->execute('checkout', '-b', $this->mainBranch);
                }
            }
            if ($needInitCommit) {
                //$repo->addAllChanges();
                $repo->addFile($gitIgnoreFile);
                $repo->commit('init commit .gitignore only');
                $repo->execute('add', '.');
                $repo->commit('Initial commit of current state');
            }
            // check current branch
            $currentBranch = $repo->getCurrentBranchName();
            if ($currentBranch !== $this->mainBranch) {
                throw new \Exception("Error branch selecting, got  branch name $currentBranch but expected: " . $this->mainBranch);
            }
        } catch (\Throwable $e) {
            $repo = true;
            echo $e->getMessage() . "\n";
            if (\property_exists($e, 'runnerResult')) {
                if ($e = $e->getRunnerResult()) {
                    \print_r($e->getErrorOutput());
                }
            }
        }
        $this->repository = $repo;
    }

    public function addFiles($filesArr, $nameSpaceKey, $replaceNameSpaceDir, $classFullName)
    {
        $this->setRepository();

        if (\is_bool($this->repository)) {
            return;
        }
        
        $msg = "Auto-commit for $nameSpaceKey";

        foreach ($filesArr as $fileShortName => $fileFullName) {
            try {
                $this->repository->execute('add', '--force', '--end-of-options', $fileFullName);
            } catch (\Throwable $e) {
                echo $e->getMessage() . "\n";
                if (\property_exists($e, 'runnerResult')) {
                    \print_r($e->getRunnerResult()->getErrorOutput());
                }
                break;
            }
        }

        $this->repository->commit($msg);
    }
}
