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

use League\Flysystem\Config;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use Qiniu\Auth;
use Qiniu\Cdn\CdnManager;
use Qiniu\Storage\BucketManager;
use Qiniu\Storage\UploadManager;
Use Qiniu\Zone;

/**
 * Class QiniuAdapter
 * @author johnny <johnnycaimail@yeah.net>
 */
class QiniuAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;

    /**
     * @var string
     */
    protected $accessKey;

    /**
     * @var string
     */
    protected $secretKey;

    /**
     * @var string
     */
    protected $bucket;

    /**
     * @var string
     */
    protected $domain;

    /**
     * @var \Qiniu\Auth
     */
    protected $authManager;

    /**
     * @var \Qiniu\Storage\UploadManager
     */
    protected $uploadManager;

    /**
     * @var \Qiniu\Storage\BucketManager
     */
    protected $bucketManager;

    /**
     * @var \Qiniu\Cdn\CdnManager
     */
    protected $cdnManager;

    /**
     * QiniuAdapter constructor.
     *
     * @param array $config
     */
    public function __construct($config = [])
    {
        $this->accessKey = $config['accessKey'];
        $this->secretKey = $config['secretKey'];
        $this->bucket = $config['bucket'];
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
        $mime = 'application/octet-stream';

        if ($config->has('mime')) {
            $mime = $config->get('mime');
        }

        list($response, $error) = $this->getUploadManager()->put(
            $this->getAuthManager()->uploadToken($this->bucket),
            $path,
            $contents,
            null,
            $mime,
            $path
        );

        if ($error) {
            return false;
        }

        return $response;
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
        $contents = '';

        while (!feof($resource)) {
            $contents .= fread($resource, 1024);
        }

        $response = $this->write($path, $contents, $config);

        if (false === $response) {
            return $response;
        }

        return compact('path');
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
        $this->delete($path);

        return $this->write($path, $contents, $config);
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
        $this->delete($path);

        return $this->writeStream($path, $resource, $config);
    }

    /**
     * Rename a file.
     *
     * @param string $path
     * @param string $newPath
     *
     * @return bool
     */
    public function rename($path, $newPath)
    {
        $response = $this->getBucketManager()->rename($this->bucket, $path, $newPath);

        return is_null($response);
    }

    /**
     * Copy a file.
     *
     * @param string $path
     * @param string $newPath
     *
     * @return bool
     */
    public function copy($path, $newPath)
    {
        $response = $this->getBucketManager()->copy($this->bucket, $path, $this->bucket, $newPath);

        return is_null($response);
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
        $response = $this->getBucketManager()->delete($this->bucket, $path);

        return is_null($response);
    }

    /**
     * Delete a directory.
     *
     * @param string $directory
     *
     * @return bool
     */
    public function deleteDir($directory)
    {
        return true;
    }

    /**
     * Create a directory.
     *
     * @param string $directory directory name
     * @param Config $config
     *
     * @return array|false
     */
    public function createDir($directory, Config $config)
    {
        return ['path' => $directory, 'type' => 'dir'];
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
        list($response, $error) = $this->getBucketManager()->stat($this->bucket, $path);

        return !$error || is_array($response);
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
        $segments = $this->parseUrl($path);
        $query = empty($segments['query']) ? '' : '?'.$segments['query'];

        return $this->normalizeHost($this->domain).ltrim(implode('/', array_map('rawurlencode', explode('/', $segments['path']))), '/').$query;
    }

    /**
     * Read a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function read($path)
    {
        $contents = file_get_contents($this->getUrl($path));

        return compact('contents', 'path');
    }

    /**
     * Read a file as a stream.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function readStream($path)
    {
        if (ini_get('allow_url_fopen')) {
            $stream = fopen($this->getUrl($path), 'r');

            return compact('stream', 'path');
        }

        return false;
    }

    /**
     * List contents of a directory.
     *
     * @param string $directory
     * @param bool   $recursive
     *
     * @return array
     */
    public function listContents($directory = '', $recursive = false)
    {
        $list = [];

        $result = $this->getBucketManager()->listFiles($this->bucket, $directory);

        foreach (isset($result[0]['items']) ? $result[0]['items'] : [] as $files) {
            $list[] = $this->normalizeFileInfo($files);
        }

        return $list;
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMetadata($path)
    {
        $result = $this->getBucketManager()->stat($this->bucket, $path);
        $result[0]['key'] = $path;

        return $this->normalizeFileInfo($result[0]);
    }

    /**
     * Get the size of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the mime-type of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMimeType($path)
    {
        $response = $this->getBucketManager()->stat($this->bucket, $path);

        if (empty($response[0]['mimeType'])) {
            return false;
        }

        return ['mimetype' => $response[0]['mimeType']];
    }

    /**
     * Get the timestamp of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * @return \Qiniu\Storage\UploadManager
     */
    protected function getUploadManager()
    {
        return $this->uploadManager ?: $this->uploadManager = new UploadManager();
    }

    /**
     * @return \Qiniu\Auth
     */
    protected function getAuthManager()
    {
        return $this->authManager ?: $this->authManager = new Auth($this->accessKey, $this->secretKey);
    }

    /**
     * @return \Qiniu\Storage\BucketManager
     */
    protected function getBucketManager()
    {
        return $this->bucketManager ?: $this->bucketManager = new BucketManager($this->getAuthManager());
    }

    /**
     * @param string $domain
     *
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
     * @param array $stats
     *
     * @return array
     */
    protected function normalizeFileInfo(array $stats)
    {
        return [
            'type' => 'file',
            'path' => $stats['key'],
            'timestamp' => floor($stats['putTime'] / 10000000),
            'size' => $stats['fsize'],
            'mimetype' => $stats['mimeType'],
            'md5' => $stats['md5'],
            'hash'  => $stats['hash']
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
        $entities = ['%21', '%2A', '%27', '%28', '%29', '%3B', '%3A', '%40', '%26', '%3D', '%24', '%2C', '%2F', '%3F', '%23', '%5B', '%5D', '%5C'];
        $replacements = ['!', '*', "'", '(', ')', ';', ':', '@', '&', '=', '$', ',', '/', '?', '#', '[', ']', '/'];

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