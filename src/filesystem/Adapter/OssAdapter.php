<?php
/* +----------------------------------------------------------------------
*  | tp6-filesystem-cloud
*  +----------------------------------------------------------------------
*  | Copyright SileStart [https://silestart.com] (c) 2013-2022 All rights reserved
*  +----------------------------------------------------------------------
*  | Author: Johnny [ johnnycaimail@yeah.net ]
*  +----------------------------------------------------------------------
*/

namespace think\filesystem\Adapter;

use OSS\OssClient;
use OSS\Core\OssException;
use League\Flysystem\Config;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Adapter\AbstractAdapter;

/**
 * Class OssAdapter
 * @author johnny <johnnycaimail@yeah.net>
 */
class OssAdapter extends AbstractAdapter
{
    /**
     * @var $client
     */
    private $client;

    /**
     * @var string $endpoint
     */
    private $endpoint;

    /**
     * @var $bucket
     */
    private $bucket;

    /**
     * @var string $domain
     */
    private $domain;

    /**
     * @var $params
     */
    private $params;

    /**
     * @var bool $useSSL
     */
    private $useSSL = false;

    /**
     * @var null
     */
    private $cdnUrl = null;

    /**
     * 初始化
     * @param array $config
     * @throws \OSS\Core\OssException
     */
    public function __construct($config = [])
    {
        $isCName = false;
        $token = null;
        $this->bucket = $config['bucket'];
        empty($config['endpoint']) ? null : $this->endpoint = trim(trim($config['endpoint']),"/");
        empty($config['timeout']) ? $config['timeout'] = 3600 : null;
        empty($config['connectTimeout']) ? $config['connectTimeout'] = 10 : null;

        if (!empty($config['isCName'])) {
            $isCName = true;
        }

        if (!empty($config['token'])) {
            $token = $config['token'];
        }

        if (!empty($config['prefix'])) {
            $this->setPathPrefix($config['prefix']);
        }

        $this->client = new OssClient(
            $config['accessKeyId'],$config['accessKeySecret'],$this->endpoint,$isCName,$token
        );
        $this->client->setTimeout($config['timeout']);
        $this->client->setConnectTimeout($config['connectTimeout']);
        $this->domain = $config['domain'];
    }

    /**
     * Write a new file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function write($path, $contents, Config $config)
    {
        $path = $this->applyPathPrefix($path);

        $options = [];
        if ($config->has('options')) {
            $options = $config->get('options');
        }
        if ($config->has("Content-Type")) {
            $options["Content-Type"] = $config->get("Content-Type");
        }
        if ($config->has("Content-Md5")) {
            $options["Content-Md5"] = $config->get("Content-Md5");
            $options["checkmd5"] = false;
        }
        $result = $this->client->putObject($this->bucket, $path, $contents, $options);
        return $result;
    }

    /**
     * Write a new file using a stream.
     *
     * @param string   $path
     * @param resource $resource
     * @param Config   $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function writeStream($path, $resource, Config $config)
    {
        $contents = stream_get_contents($resource);

        return $this->write($path, $contents, $config);
    }

    /**
     * Update a file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function update($path, $contents, Config $config)
    {
        $options = [];
        if ($config->has("Content-Type")) {
            $options["Content-Type"] = $config->get("Content-Type");
        }
        if ($config->has("Content-Md5")) {
            $options["Content-Md5"] = $config->get("Content-Md5");
            $options["checkmd5"] = false;
        }
        $result = $this->client->putObject($this->bucket, $path, $contents, $options);
        return $result;
    }

    /**
     * Update a file using a stream.
     *
     * @param string   $path
     * @param resource $resource
     * @param Config   $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function updateStream($path, $resource, Config $config)
    {
        $result = $this->write($path, stream_get_contents($resource), $config);
        if (is_resource($resource)) {
            fclose($resource);
        }
        return $result;
    }

    /**
     * Rename a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function rename($path, $newpath)
    {
        if (!$this->copy($path, $newpath)) {
            return false;
        }

        return $this->delete($path);
    }

    /**
     * Copy a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function copy($path, $newpath)
    {
        $path = $this->applyPathPrefix($path);
        $newpath = $this->applyPathPrefix($newpath);

        try {
            $this->client->copyObject($this->bucket, $path, $this->bucket, $newpath);
        } catch (OssException $exception) {
            return false;
        }

        return true;
    }

    /**
     * Delete a file.
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete($path)
    {
        $path = $this->applyPathPrefix($path);

        try {
            $this->client->deleteObject($this->bucket, $path);
        } catch (OssException $ossException) {
            return false;
        }

        return !$this->has($path);
    }

    /**
     * Delete a directory.
     *
     * @param string $dirname
     *
     * @return bool
     */
    public function deleteDir($dirname)
    {
        $fileList = $this->listContents($dirname, true);
        foreach ($fileList as $file) {
            $this->delete($file['path']);
        }

        return !$this->has($dirname);
    }

    /**
     * Create a directory.
     *
     * @param string $dirname directory name
     * @param Config $config
     *
     * @return array|false
     */
    public function createDir($dirname, Config $config)
    {
        $this->client->createObjectDir($this->bucket, $dirname);
        return true;
    }

    /**
     * Check whether a file exists.
     *
     * @param string $path
     *
     * @return array|bool|null
     */
    public function has($path)
    {
        $path = $this->applyPathPrefix($path);

        return $this->client->doesObjectExist($this->bucket, $path);
    }

    /**
     * Get resource url.
     *
     * @param string $path
     *
     * @return string
     */
    public function getUrl($path)
    {
        $path = $this->applyPathPrefix($path);

        $segments = $this->parseUrl($path);
        $query = empty($segments['query']) ? '' : '?'.$segments['query'];

        return $this->normalizeHost($this->domain).ltrim(implode('/', array_map('urlencode', explode('/', $segments['path']))), '/').$query;
    }

    /**
     * Set the visibility for a file.
     *
     * @param string $path
     * @param string $visibility
     *
     * @return array|false file meta data
     */
    public function setVisibility($path, $visibility)
    {
        $object = $this->applyPathPrefix($path);
        $acl = (AdapterInterface::VISIBILITY_PUBLIC === $visibility) ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE;

        try {
            $this->client->putObjectAcl($this->bucket, $object, $acl);
        } catch (OssException $exception) {
            return false;
        }

        return compact('visibility');
    }

    /**
     * read a file.
     *
     * @param string $path
     *
     * @return array|bool|false
     */
    public function read($path)
    {
        try {
            $contents = $this->getObject($path);
        } catch (OssException $exception) {
            return false;
        }

        return compact('contents', 'path');
    }

    /**
     * read a file stream.
     *
     * @param string $path
     *
     * @return array|bool|false
     */
    public function readStream($path)
    {
        try {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $this->getObject($path));
            rewind($stream);
        } catch (OssException $exception) {
            return false;
        }

        return compact('stream');
    }

    /**
     * File list core method.
     *
     * @param string $dirname
     * @param bool   $recursive
     *
     * @return array
     *
     * @throws OssException
     */
    public function listDirObjects($dirname = '', $recursive = false)
    {
        $delimiter = '/';
        $nextMarker = '';
        $maxkeys = 1000;

        $result = [];

        while (true) {
            $options = [
                'delimiter' => $delimiter,
                'prefix' => $dirname,
                'max-keys' => $maxkeys,
                'marker' => $nextMarker,
            ];

            try {
                $listObjectInfo = $this->client->listObjects($this->bucket, $options);
            } catch (OssException $exception) {
                throw $exception;
            }

            $nextMarker = $listObjectInfo->getNextMarker();
            $objectList = $listObjectInfo->getObjectList(); // 文件列表
            $prefixList = $listObjectInfo->getPrefixList(); // 目录列表

            if (!empty($objectList)) {
                foreach ($objectList as $objectInfo) {
                    $object['Prefix'] = $dirname;
                    $object['Key'] = $objectInfo->getKey();
                    $object['LastModified'] = $objectInfo->getLastModified();
                    $object['eTag'] = $objectInfo->getETag();
                    $object['Type'] = $objectInfo->getType();
                    $object['Size'] = $objectInfo->getSize();
                    $object['StorageClass'] = $objectInfo->getStorageClass();
                    $result['objects'][] = $object;
                }
            } else {
                $result['objects'] = [];
            }

            if (!empty($prefixList)) {
                foreach ($prefixList as $prefixInfo) {
                    $result['prefix'][] = $prefixInfo->getPrefix();
                }
            } else {
                $result['prefix'] = [];
            }

            // Recursive directory
            if ($recursive) {
                foreach ($result['prefix'] as $prefix) {
                    $next = $this->listDirObjects($prefix, $recursive);
                    $result['objects'] = array_merge($result['objects'], $next['objects']);
                }
            }

            if ('' === $nextMarker) {
                break;
            }
        }

        return $result;
    }

    /**
     * List contents of a directory.
     *
     * @param string $directory
     * @param bool $recursive
     *
     * @return array
     */
    public function listContents($directory = '', $recursive = false)
    {
        $list = [];
        $directory = '/' == substr($directory, -1) ? $directory : $directory.'/';
        $result = $this->listDirObjects($directory, $recursive);

        if (!empty($result['objects'])) {
            foreach ($result['objects'] as $files) {
                if ('oss.txt' == substr($files['Key'], -7) || !$fileInfo = $this->normalizeFileInfo($files)) {
                    continue;
                }
                $list[] = $fileInfo;
            }
        }

        // prefix
        if (!empty($result['prefix'])) {
            foreach ($result['prefix'] as $dir) {
                $list[] = [
                    'type' => 'dir',
                    'path' => $dir,
                ];
            }
        }

        return $list;
    }

    /**
     * get meta data.
     *
     * @param string $path
     *
     * @return array|bool|false
     */
    public function getMetadata($path)
    {
        $path = $this->applyPathPrefix($path);

        try {
            $metadata = $this->client->getObjectMeta($this->bucket, $path);
        } catch (OssException $exception) {
            return false;
        }

        return $metadata;
    }

    /**
     * get the size of file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getSize($path)
    {
        return $this->normalizeFileInfo(['Key' => $path]);
    }

    /**
     * get mime type.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMimetype($path)
    {
        return $this->normalizeFileInfo(['Key' => $path]);
    }

    /**
     * get timestamp.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getTimestamp($path)
    {
        return $this->normalizeFileInfo(['Key' => $path]);
    }

    /**
     * Get the visibility of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getVisibility($path)
    {
        $path = $this->applyPathPrefix($path);

        $response = $this->client->getObjectAcl($this->bucket, $path);
        return [
            'visibility' => $response,
        ];
    }

    /**
     * normalize Host.
     *
     * @param string $domain
     * @return string
     */
    protected function normalizeHost($domain)
    {
        if (0 !== stripos($domain, 'https://') && 0 !== stripos($domain, 'http://')) {
            $domain = "http://{$domain}";
        }

        return rtrim($domain, '/').'/';
    }

    /**
     * Read an object from the OssClient.
     *
     * @param $path
     *
     * @return string
     */
    protected function getObject($path)
    {
        $path = $this->applyPathPrefix($path);

        return $this->client->getObject($this->bucket, $path);
    }

    /**
     * normalize file info.
     *
     * @return array
     */
    protected function normalizeFileInfo(array $stats)
    {
        $filePath = ltrim($stats['Key'], '/');

        $meta = $this->getMetadata($filePath) ?? [];

        if (empty($meta)) {
            return [];
        }

        return [
            'type' => 'file',
            'path' => $filePath,
            'timestamp' => $meta['info']['filetime'],
            'size' => $meta['content-length'],
            'mimetype' => $meta['content-type'],
            'md5'   => $meta['Content-Md5'],
        ];
    }

    /**
     * Does a UTF-8 safe version of PHP parse_url function.
     *
     * @param string $url URL to parse
     *
     * @return mixed associative array or false if badly formed URL
     *
     * @see     http://us3.php.net/manual/en/function.parse-url.php
     * @since   11.1
     */
    protected static function parseUrl($url)
    {
        $result = false;

        // Build arrays of values we need to decode before parsing
        $entities = array('%21', '%2A', '%27', '%28', '%29', '%3B', '%3A', '%40', '%26', '%3D', '%24', '%2C', '%2F', '%3F', '%23', '%5B', '%5D');
        $replacements = array('!', '*', "'", '(', ')', ';', ':', '@', '&', '=', '$', ',', '/', '?', '#', '[', ']');

        // Create encoded URL with special URL characters decoded so it can be parsed
        // All other characters will be encoded
        $encodedURL = str_replace($entities, $replacements, urlencode($url));

        // Parse the encoded URL
        $encodedParts = parse_url($encodedURL);

        // Now, decode each value of the resulting array
        if ($encodedParts) {
            foreach ($encodedParts as $key => $value) {
                $result[$key] = urldecode(str_replace($replacements, $entities, $value));
            }
        }

        return $result;
    }
}