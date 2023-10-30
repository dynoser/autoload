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
            $chkGitPath = $this->sitePath . '/.git';
            if (!\file_exists($chkGitPath)) {
                $repo = $this->gitObj->init($sitePath);
                $currentBranch = '';
            } else {
                $repo = $this->gitObj->open($sitePath);
                $currentBranch = $repo->getCurrentBranchName();
            }
            if ($currentBranch !== $this->mainBranch) {
                $repo->execute('checkout', '-b', $this->mainBranch);
            }
            if (!$currentBranch) {
                $repo->execute('add', '.');
                $repo->commit('Initial commit');
            }
        } catch (\Throwable $e) {
            $repo = true;
            echo $e->getMessage() . "\n";
            if (\property_exists($e, 'runnerResult')) {
                \print_r($e->getRunnerResult()->getErrorOutput());
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
