<?php

/**
 * Represents a wrapper for text-based packages (manifested by a package-folder and a package.xml).
 * Provides extended functions for handling text-based and binary packages.
 *
 * @author Oliver Erdmann, o.erdmann@finaldream.de
 */
class psdContentClassPackage
{

    /**
     * Name of the package (relates to a folder inside the repository).
     *
     * @var string
     */
    protected $packageName = '';

    /**
     * Path to the repository, which holds folders with package and class-definitions.
     *
     * @var string
     */
    protected $repoPath = '';

    /**
     * Outputs info on the command-line.
     *
     * @var boolean
     */
    protected $verbose = false;

    /**
     * Commandline-interface.
     *
     * @var eZCLI
     */
    protected $cli = null;


    /**
     * Create a PSD content class package instance.
     *
     * @param boolean $verbose If it should be printed to command line.
     */
    public function __construct($verbose = false)
    {
        $this->cli     = eZCLI::instance();
        $this->verbose = $verbose;

    }


    /**
     * Sets repository and package-name for a folder-based package.
     *
     * @param string $repository  The name of the repository (folder).
     * @param string $packageName The name of the package.
     *
     * @return void
     */
    public function load($repository, $packageName)
    {

        $this->repoPath    = $repository;
        $this->packageName = $packageName;

    }


    /**
     * Returns the name of the currently loaded package.
     *
     * @return string
     */
    public function getPackageName()
    {
        return $this->packageName;

    }


    /**
     * Returns the repository-path of the currently loaded package.
     *
     * @return string
     */
    public function getRepoPath()
    {
        return $this->repoPath;

    }


    /**
     * Loads a folder-based package from a given path. The last path-segment is used as package-name,
     * everything before is used as repository-path.
     *
     * @param string $path Must be the folder for the package.
     *
     * @return boolean Success or failure.
     */
    public function loadFromPath($path)
    {
        $path = realpath($path);

        // Simple check if the folder contains a package.
        if (is_dir($path) === false) {
            $this->logLine(sprintf('The provided path %s is not a folder!', $path), __METHOD__);
            return false;
        }

        if (file_exists(eZDir::path(array($path, 'package.xml'))) === false) {
            $this->logLine(sprintf('The provided path %s is not a package!', $path), __METHOD__);
            return false;
        }

        $parts = pathinfo($path);

        $this->packageName = $parts['basename'];
        $this->repoPath    = $parts['dirname'];

        return true;

    }


    /**
     * Extracts one or multiple (through wildcards) binary packages into their text-based representation and finally
     * transforms the contents of the class-definitions in order to make editing a bit easier.
     *
     * @param string $filePattern File to be processed, may contain wildcards.
     *
     * @throws Exception
     *
     * @return void
     */
    public function extractAndTransform($filePattern)
    {

        $files = glob($filePattern);

        if ($files === false) {
            throw new Exception(sprintf('Pattern "%s" does not match any files.', $filePattern));
        }

        foreach ($files as $file) {

            $info = pathinfo($file);

            $isEzpkg = false;

            if ($info['extension'] === 'ezpkg') {
                $isEzpkg = true;
            }

            if (!file_exists($file) || $isEzpkg === false) {
                continue;
            }

            $this->extractSinglePackage($file);

            // Find all class-definitions in a package.
            $classDefinition = eZDir::path(
                array($this->getPackagePathFromFile($file), 'ezcontentclass', 'class-*.xml')
            );
            $classes = glob($classDefinition);

            if (count($classes) < 1) {
                $this->logLine('Package contains no class definitions. '.$file, __METHOD__);
            }

            foreach ($classes as $c) {
                if (file_exists($c) === false) {
                    trigger_error('Class-definition not found:'.$c);
                } else {
                    $class = new psdContentClassDefinition($c, $this->verbose);
                    $class->transformXML();
                }
            }
        }//end foreach

    }


    /**
     * Extracts a single binary package (.ezpkg-file) into it's text-based representation.
     *
     * @param string $fileName File to be processed (no wildcards here!).
     *
     * @return void
     */
    public function extractSinglePackage($fileName)
    {

        $fileName    = realpath($fileName);
        $packagePath = $this->getPackagePathFromFile($fileName);

        if (empty($packagePath)) {
            trigger_error('Empty is not a valid package-name.');
        }

        if (file_exists($fileName) === false) {
            trigger_error('File does not exist! '.$fileName);
        }

        ezDir::mkdir($packagePath, false, true);

        $this->logLine('Extracting Binary Package '.$fileName, __METHOD__);

        $archiveOptions = new ezcArchiveOptions(array('readOnly' => true));
        $archive        = ezcArchive::open('compress.zlib://'.$fileName, ezcArchive::TAR_GNU, $archiveOptions);

        $archive->extract($packagePath);

    }


    /**
     * Extracts the package-name from the name of a given binary package. This can be used to access or create the new
     * package's folder.
     *
     * @param string $fileName Filename, must be a binary package like that: ../path/to/repo/mypackage-1.1-1.ezpkg
     *                         (the version-string is optional).
     *
     * @return string
     */
    public function getPackageNameFromFile($fileName)
    {
        $file = basename($fileName);

        // Get rid of the version and extension.
        $result = explode('-', $file);

        // Or just the version.
        if (count($result) < 2) {
            $result = explode('.', $file);
            array_pop($result);
        }

        // Just take the first element.
        if (count($result) > 0) {
            return $result[0];
        }

        return '';

    }


    /**
     * Get's the final package-path from a binary package. Helps for converting both.
     *
     * @param string $fileName Filename, must be a binary package like that: ../path/to/repo/mypackage-1.1-1.ezpkg
     *                         (the version-string is optional).
     *
     * @return string
     */
    public function getPackagePathFromFile($fileName)
    {
        $repo = dirname($fileName);

        return eZDir::path(array($repo, $this->getPackageNameFromFile($fileName)));

    }


    /**
     * Installs the current text-based package. Use load() or loadFromPath() to specify which package.
     *
     * @param bool $checkVersion If true, the package's classes will only be installed, if newer. This check is
     *                           performed for each content-class rather than the whole package.
     *
     * @return bool Fail / Success.
     */
    public function install($checkVersion = true)
    {
        $package = $this->getPackageFromFile($this->packageName, $this->repoPath);

        if (!($package instanceof \eZPackage)) {
            trigger_error('Unable to load package %s from %s.', $this->packageName, $this->repoPath);
            return false;
        }

        $this->logLine(sprintf('Installing XML-package "%s" from %s', $this->packageName, $this->repoPath), __METHOD__);

        $installParameters = $this->getInstallParametersForPackage($package);

        $package->checkForInstalledVersion = $checkVersion;

        return $package->install($installParameters);

    }


    /**
     * Uninstalls the current text-based package. Use load() or loadFromPath() to specify which package.
     *
     * Keep in mind: you can only uninstall classes that don't have instances! In order to remove these classes, first
     * remove their objects!
     *
     * @return bool Fail / Success.
     */
    public function uninstall()
    {

        $package = $this->getPackageFromFile($this->packageName, $this->repoPath);
        if (!($package instanceof \eZPackage)) {
            trigger_error('Unable to load package %s from %s.', $this->packageName, $this->repoPath);
            return false;
        }

        $this->logLine(
            sprintf('Uninstalling XML-package "%s" from %s', $this->packageName, $this->repoPath), __METHOD__
        );

        $installParameters    = $this->getInstallParametersForPackage($package);
        $package->isInstalled = true;

        return $package->uninstall($installParameters);

    }


    /**
     * Prepares install-parameters for the provided package that are needed for un/installing.
     *
     * @param eZPackage $package The eZPackage instance.
     *
     * @return array
     */
    public function getInstallParametersForPackage(\eZPackage $package)
    {

        $user = \eZUser::currentUser();

        $result = array(
            'site_access_map' => array('*' => false),
            'top_nodes_map'   => array('*' => 2 ),
            'design_map'      => array('*' => false),
            'restore_dates'   => true,
            'user_id'         => $user->attribute('contentobject_id'),
            'non-interactive' => true,
            'language_map'    => $package->defaultLanguageMap()
        );

        return $result;

    }


    /**
     * Creates an psdPackage-Instance from a text-based package.
     * The package's location is: $repositoryPath/$name/package.xml
     *
     * @param string $name           The package-name, is appended to the repository-path.
     * @param string $repositoryPath The path where the package-folder is stored.
     *
     * @throws Exception             If package is empty or no XML.
     *
     * @return bool|psdPackage       The psdPackage-Instance or false on failure.
     */
    public function getPackageFromFile($name, $repositoryPath)
    {

        $repositoryPath = realpath($repositoryPath);
        $packagePath    = realpath(implode('/', array($repositoryPath, $name)));
        $packageFile    = realpath(implode('/', array($packagePath, 'package.xml')));

        // Abort migration if package does not exists.
        if (!file_exists($packagePath)) {
            throw new Exception('Package-Path '.$packagePath.' does not exists.');
        }

        if (!file_exists($packageFile)) {
            throw new Exception('Package-File '.$packageFile.' does not exists.');
        };

        $package = \psdPackage::fetch($name);

        if ($package !== false) {
            $package->remove();
        }

        // This basically resembles \eZPackage::fetchFromFile(), but with the addition to specify a
        // custom repository-path.
        $dom = \eZPackage::fetchDOMFromFile($packageFile);

        if ($dom === false) {
            throw new Exception('Package-File '.$packageFile.' is empty or not XML.');
        }

        $package = new \psdPackage(array(), $repositoryPath);

        $parameters = $package->parseDOMTree($dom);
        if (!$parameters) {
            throw new Exception('Package-File '.$packageFile.' does not contain parameters.');
        }

        return $package;

    }


    /**
     * Installs a new binary package (*.ezpkg).
     *
     * @param string $name The name for the package.
     * @param string $file The location of the package.
     *
     * @return void
     */
    public function installBinaryPackage($name, $file)
    {
        if (!file_exists($file)) {
            sprintf('File %s does not exists.', $file);
            return;
        }

        $this->logLine('Install binary package "'.$name.'" from file '.$file, __METHOD__);

        $package = \eZPackage::fetch($name);

        if ($package !== false) {
            $package->remove();
        }

        $installParameters = $this->getInstallParametersForPackage($package);

        $package->install($installParameters);

    }


    /**
     * Replaces an existing binary package (*.ezpkg) with another.
     *
     * The package with $name is replaced with the new package. Therefore the old one
     * is removed and the new one is installed.
     *
     * @param string $name           The name of the old / new package.
     * @param string $file           The file of the new package.
     * @param string $newPackageName Sets the name of the new package.
     *
     * @return void
     */
    public function replaceBinaryPackageWith($name, $file, $newPackageName = null)
    {
        $this->logLine('Replace binary package \"'.$name.'\" with '.$file, __METHOD__);

        $this->removeBinaryPackage($name);

        if ($newPackageName !== null) {
            $name = $newPackageName;
        }

        $this->installBinaryPackage($name, $file);

    }


    /**
     * Removes a binary package (*.ezpkg) with a specific name.
     *
     * @param string $name Package name.
     *
     * @return void
     */
    public function removeBinaryPackage($name)
    {

        $this->logLine('Remove binary package "'.$name.'"', __METHOD__);

        $package = \eZPackage::fetch($name);

        if ($package !== false) {
            $package->remove();
        }

    }


    /**
     * Changes the class-identifier of a given Content-Object.
     *
     * @param int    $objectId        Object to modify.
     * @param string $classIdentifier New class-identifier.
     *
     * @throws Exception If object id or class identifier is wrong.
     *
     * @return void
     */
    public function changeClassIdentifierOfObject($objectId, $classIdentifier)
    {
        $object = eZContentObject::fetch($objectId);
        $class  = eZContentClass::fetchByIdentifier($classIdentifier);

        if ($object instanceof eZContentObject && $class instanceof eZContentClass) {

            $this->logLine(
                sprintf(
                    'Changing object "%s" to be of class "%s"',
                    $object->attribute('name'), $class->attribute('identifier')
                ),
                __METHOD__
            );

            $object->setAttribute('contentclass_id', $class->attribute('id'));
            $object->store();

            eZContentCacheManager::clearContentCache($objectId);

            return;
        }

        throw new Exception(sprintf('Invalid Object-ID (%s) or Class-Identifier (%s)!', $objectId, $classIdentifier));

    }


    /**
     * Writes a line to the console if $verbose is enabled.
     *
     * @param string $str    Message to be written.
     * @param string $method Optional Method name, only used for debug-log.
     *
     * @return void
     */
    public function logLine($str, $method = '')
    {

        eZDebug::writeNotice('*'.__CLASS__.': '.$str, $method);

        if (!$this->verbose) {
            return;
        }

        $this->cli->output($str, true);

    }


}
