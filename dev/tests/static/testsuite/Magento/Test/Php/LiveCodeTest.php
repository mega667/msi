<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Test\Php;

use Magento\Framework\App\Utility\Files;
use Magento\TestFramework\CodingStandard\Tool\CodeMessDetector;
use Magento\TestFramework\CodingStandard\Tool\CodeSniffer;
use Magento\TestFramework\CodingStandard\Tool\CodeSniffer\Wrapper;
use Magento\TestFramework\CodingStandard\Tool\CopyPasteDetector;
use PHPMD\TextUI\Command;

/**
 * Set of tests for static code analysis, e.g. code style, code complexity, copy paste detecting, etc.
 */
class LiveCodeTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var string
     */
    protected static $reportDir = '';

    /**
     * @var string
     */
    protected static $pathToSource = '';

    /**
     * Setup basics for all tests
     *
     * @return void
     */
    public static function setUpBeforeClass()
    {
        self::$pathToSource = BP;
        self::$reportDir = self::$pathToSource . '/dev/tests/static/report';
        if (!is_dir(self::$reportDir)) {
            mkdir(self::$reportDir);
        }
    }

    /**
     * Returns base folder for suite scope
     *
     * @return string
     */
    private static function getBaseFilesFolder()
    {
        return __DIR__;
    }

    /**
     * Returns base directory for whitelisted files
     *
     * @return string
     */
    private static function getChangedFilesBaseDir()
    {
        return __DIR__ . '/..';
    }

    /**
     * Returns whitelist based on blacklist and git changed files
     *
     * @param array $fileTypes
     * @param string $changedFilesBaseDir
     * @param string $baseFilesFolder
     * @param string $whitelistFile
     * @return array
     */
    public static function getWhitelist(
        $fileTypes = ['php'],
        $changedFilesBaseDir = '',
        $baseFilesFolder = '',
        $whitelistFile = '/_files/whitelist/common.txt'
    ) {
        $changedFiles = self::getChangedFilesList($changedFilesBaseDir);
        if (empty($changedFiles)) {
            return [];
        }

        $globPatternsFolder = ('' !== $baseFilesFolder) ? $baseFilesFolder : self::getBaseFilesFolder();
        try {
            $directoriesToCheck = Files::init()->readLists($globPatternsFolder . $whitelistFile);
        } catch (\Exception $e) {
            // no directories matched white list
            return [];
        }
        $targetFiles = self::filterFiles($changedFiles, $fileTypes, $directoriesToCheck);
        return $targetFiles;
    }

    /**
     * This method loads list of changed files.
     *
     * List may be generated by:
     *  - dev/tests/static/get_github_changes.php utility (allow to generate diffs between branches),
     *  - CLI command "git diff --name-only > dev/tests/static/testsuite/Magento/Test/_files/changed_files_local.txt",
     *
     * If no generated changed files list found "git diff" will be used to find not committed changed
     * (tests should be invoked from target gir repo).
     *
     * Note: "static" modifier used for compatibility with legacy implementation of self::getWhitelist method
     *
     * @param string $changedFilesBaseDir Base dir with previously generated list files
     * @return string[] List of changed files
     */
    private static function getChangedFilesList($changedFilesBaseDir)
    {
        $changedFiles = [];

        $globFilesListPattern = ($changedFilesBaseDir ?: self::getChangedFilesBaseDir()) . '/_files/changed_files*';
        $listFiles = glob($globFilesListPattern);
        if (count($listFiles)) {
            foreach ($listFiles as $listFile) {
                $changedFiles = array_merge(
                    $changedFiles,
                    file($listFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
                );
            }
        } else {
            // if no list files, probably, this is the dev environment
            @exec('git diff --name-only', $changedFiles);
        }

        array_walk(
            $changedFiles,
            function (&$file) {
                $file = BP . '/' . $file;
            }
        );

        return $changedFiles;
    }

    /**
     * Filter list of files.
     *
     * File removed from list:
     *  - if it not exists,
     *  - if allowed types are specified and file has another type (extension),
     *  - if allowed directories specified and file not located in one of them.
     *
     * Note: "static" modifier used for compatibility with legacy implementation of self::getWhitelist method
     *
     * @param string[] $files List of file paths to filter
     * @param string[] $allowedFileTypes List of allowed file extensions (pass empty array to allow all)
     * @param string[] $allowedDirectories List of allowed directories (pass empty array to allow all)
     * @return string[] Filtered file paths
     */
    private static function filterFiles(array $files, array $allowedFileTypes, array $allowedDirectories)
    {
        if (empty($allowedFileTypes)) {
            $fileHasAllowedType = function () {
                return true;
            };
        } else {
            $fileHasAllowedType = function ($file) use ($allowedFileTypes) {
                return in_array(pathinfo($file, PATHINFO_EXTENSION), $allowedFileTypes);
            };
        }

        if (empty($allowedDirectories)) {
            $fileIsInAllowedDirectory = function () {
                return true;
            };
        } else {
            $allowedDirectories = array_map('realpath', $allowedDirectories);
            usort($allowedDirectories, function ($dir1, $dir2) {
                return strlen($dir1) - strlen($dir2);
            });
            $fileIsInAllowedDirectory = function ($file) use ($allowedDirectories) {
                foreach ($allowedDirectories as $directory) {
                    if (strpos($file, $directory) === 0) {
                        return true;
                    }
                }
                return false;
            };
        }

        $filtered = array_filter(
            $files,
            function ($file) use ($fileHasAllowedType, $fileIsInAllowedDirectory) {
                $file = realpath($file);
                if (false === $file) {
                    return false;
                }
                return $fileHasAllowedType($file) && $fileIsInAllowedDirectory($file);
            }
        );

        return $filtered;
    }

    /**
     * Retrieves full list of codebase paths without any files/folders filtered out
     *
     * @return array
     */
    private function getFullWhitelist()
    {
        try {
            return Files::init()->readLists(__DIR__ . '/_files/whitelist/common.txt');
        } catch (\Exception $e) {
            // nothing is whitelisted
            return [];
        }
    }

    public function testCodeStyle()
    {
        $isFullScan = defined('TESTCODESTYLE_IS_FULL_SCAN') && TESTCODESTYLE_IS_FULL_SCAN === '1';
        $reportFile = self::$reportDir . '/phpcs_report.txt';
        $codeSniffer = new CodeSniffer('Magento', $reportFile, new Wrapper());
        $result = $codeSniffer->run($isFullScan ? $this->getFullWhitelist() : self::getWhitelist(['php', 'phtml']));
        $report = file_get_contents($reportFile);
        $this->assertEquals(
            0,
            $result,
            "PHP Code Sniffer detected {$result} violation(s): " . PHP_EOL . $report
        );
    }

    public function testCodeMess()
    {
        $reportFile = self::$reportDir . '/phpmd_report.txt';
        $codeMessDetector = new CodeMessDetector(realpath(__DIR__ . '/_files/phpmd/ruleset.xml'), $reportFile);

        if (!$codeMessDetector->canRun()) {
            $this->markTestSkipped('PHP Mess Detector is not available.');
        }

        $result = $codeMessDetector->run(self::getWhitelist(['php']));

        $output = "";
        if (file_exists($reportFile)) {
            $output = file_get_contents($reportFile);
        }

        $this->assertEquals(
            Command::EXIT_SUCCESS,
            $result,
            "PHP Code Mess has found error(s):" . PHP_EOL . $output
        );

        // delete empty reports
        if (file_exists($reportFile)) {
            unlink($reportFile);
        }
    }

    public function testCopyPaste()
    {
        $reportFile = self::$reportDir . '/phpcpd_report.xml';
        $copyPasteDetector = new CopyPasteDetector($reportFile);

        if (!$copyPasteDetector->canRun()) {
            $this->markTestSkipped('PHP Copy/Paste Detector is not available.');
        }

        $blackList = [];
        foreach (glob(__DIR__ . '/_files/phpcpd/blacklist/*.txt') as $list) {
            $blackList = array_merge($blackList, file($list, FILE_IGNORE_NEW_LINES));
        }

        $copyPasteDetector->setBlackList($blackList);

        $result = $copyPasteDetector->run([BP]);

        $output = "";
        if (file_exists($reportFile)) {
            $output = file_get_contents($reportFile);
        }

        $this->assertTrue(
            $result,
            "PHP Copy/Paste Detector has found error(s):" . PHP_EOL . $output
        );
    }

    /**
     * Tests whitelisted files for strict type declarations.
     */
    public function testStrictTypes()
    {
        $whiteList = self::getWhitelist(
            ['php'],
            '',
            '',
            '/_files/whitelist/strict_type.txt'
        );
        try {
            $blackList = Files::init()->readLists(
                self::getBaseFilesFolder() . '/_files/blacklist/strict_type.txt'
            );
        } catch (\Exception $e) {
            // nothing matched black list
            $blackList = [];
        }
        $toBeTestedFiles = array_diff($whiteList, $blackList);

        $filesMissingStrictTyping = [];
        foreach ($toBeTestedFiles as $fileName) {
            $file = file_get_contents($fileName);
            if (strstr($file, 'strict_types=1') === false) {
                $filesMissingStrictTyping[] = $fileName;
            }
        }
        $filesMissingStrictTypingString = implode(PHP_EOL, $filesMissingStrictTyping);

        $this->assertEquals(
            0,
            count($filesMissingStrictTyping),
            "Following files are missing strict type declaration:" . PHP_EOL . $filesMissingStrictTypingString
        );
    }

    public function testCodeFreeze()
    {
        $changedFiles = self::getChangedFilesList('');
        if (empty($changedFiles)) {
            return;
        }

        $allowedChanges = Files::init()->readLists(
            self::getBaseFilesFolder() . '/_files/blacklist/code_freeze.txt'
        );

        $changedFrozenCode = [];
        foreach ($changedFiles as $changedFile) {
            foreach ($allowedChanges as $notFrozenCode) {
                if (0 === strpos($changedFile, $notFrozenCode)) {
                    continue 2;
                }
            }
            $changedFrozenCode[] = $changedFile;
        }

        $changedFrozenCode = array_map(function ($file) {
            return substr($file, strlen(self::$pathToSource) + 1);
        }, $changedFrozenCode);
        sort($changedFrozenCode, SORT_NATURAL);

        $this->assertEmpty(
            $changedFrozenCode,
            sprintf(
                "Frozen code must not be modified. %d modified files detected:\n\t%s",
                count($changedFrozenCode),
                join("\n\t",$changedFrozenCode)
            )
        );
    }
}
