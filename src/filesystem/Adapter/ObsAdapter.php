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

use Obs\ObsClient;
use Obs\ObsException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use League\Flysystem\Config;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Adapter\AbstractAdapter;

/**
 * Class ObsAdapter
 * @author johnny <johnnycaimail@yeah.net>
 */
class ObsAdapter extends AbstractAdapter
{
    /**
     * @var \Obs\ObsClient
     */
    protected $client;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var $bucket
     */
    protected $bucket;

    /**
     * @var mixed[]|array<string, bool>|array<string, string>
     * @phpstan-var array{url?: string, temporary_url?: string, endpoint?: string, bucket_endpoint?: bool}
     */
    protected $options = [];

    /**
     * @var string $endpoint
     */
    private $endpoint;

    /**
     * @var string $domain
     */
    private $domain;

    /**
     * ObsAdapter constructor.
     *
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->endpoint = $config['endpoint'];
        $this->domain = $config['domain'];

        $this->client = new ObsClient([
            'key' => $config['accessKey'],
            'secret' => $config['secretKey'],
            'endpoint' => $config['endpoint'],
        ]);
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
        try {
            $resp = $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
                'Body' => $contents,
            ]);
            return $resp->toArray();
        } catch (ObsException $obsException) {
            return false;
        }
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
        try {
            $contents = stream_get_contents($resource);

            $result = $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
                'Body' => $contents,
            ]);

            if (is_resource($resource)) {
                fclose($resource);
            }

            return $result->toArray();

        } catch (ObsException $obsException) {
            return false;
        }
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
        return $this->writeStream($path, $resource, $config);
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
        try {
            if ($this->copy($path, $newpath)) {
                return $this->delete($path);
            }

            return false;

        } catch (ObsException $exception) {
            return false;
        }
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
        try {
            $result = $this->client->copyObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
                'CopySource' => $newpath,
            ]);

            return $result['HttpStatusCode'] == 200;

        } catch (ObsException $exception) {
            return false;
        }
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
        try {
            $result = $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);

            return $result['HttpStatusCode'] == 200;

        } catch (ObsException $exception) {
            return false;
        }
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
        try {
            $this->write(trim($dirname, '/') . '/', '', $config);
        } catch (ObsException $exception) {
            return false;
        }
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
        try {
            return (bool) $this->getMetadata($path);
        } catch (ObsException $exception) {
            return false;
        }
    }

    /**
     * getUrl
     *
     * @param string $path
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
        $path = $this->applyPathPrefix($path);

        $acl = (AdapterInterface::VISIBILITY_PUBLIC === $visibility) ? ObsClient::AclPublicRead : ObsClient::AclPrivate;

        try {
            $resp = $this->client->setObjectAcl([
                'Bucket' => $this->bucket,
                'Key' => $path,
                'ACL' => $acl,
            ]);
            return $resp['HttpStatusCode'] == 200;
        } catch (ObsException $exception) {
            return false;
        }
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
        $resp = $this->getObject($path)
            ->getContents();

        return ['contents' => (string)$resp];
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
        $resp = $this->getObject($path)
            ->detach();

        return ['stream' => $resp];
    }

    /**
     * File list core method.
     * 单次调用 listObjects 接口一次只能查询1000个对象，如需要查询所有的对象，则需要循环调用。
     * @param string $dirname
     * @param bool   $recursive
     * @return array
     */
    public function listDirObjects($directory = '', $recursive = false) {
        $delimiter = '/';
        $nextMarker = '';

        $result = [];

        while (true) {
            $options = [
                'Bucket' => $this->bucket,
                'Prefix' => $directory,
                'Marker' => $nextMarker,
                'MaxKeys' => 1000,
                'Delimiter' => $delimiter,
            ];

            try {
                $model = $this->client->listObjects($this->bucket, $options);
            } catch (ObsException $exception) {
                throw $exception;
            }

            $nextMarker = $model['NextMarker'];
            $objectList = $model['Contents'];
            $prefixList = $model['CommonPrefixes'];

            if (!empty($objectList)) {
                foreach ($objectList as $objectInfo) {
                    $object['Prefix'] = $directory;
                    $object['Key'] = $objectInfo['Key'];
                    $object['LastModified'] = $objectInfo['LastModified'];
                    $object['eTag'] = $objectInfo['ETag'];
                    $object['Size'] = $objectInfo['Size'];
                    $object['StorageClass'] = $objectInfo['StorageClass'];
                    $result['objects'][] = $object;
                }
            } else {
                $result['objects'] = [];
            }

            if (!empty($prefixList)) {
                foreach ($prefixList as $prefixInfo) {
                    $result['prefix'][] = $prefixInfo['Prefix'];
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
                if ('obs.txt' == substr($files['Key'], -7) || !$fileInfo = $this->normalizeFileInfo($files)) {
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
        $metadata = [];

        try {
            $result = $this->client->getObjectMetadata([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);

            if($result['HttpStatusCode'] == 200) {
                $metadata = [
                    'Key' => $path,
                    'LastModified' => $result['LastModified'],
                    'eTag'  => $result['ETag'],
                    'Size' => $result['LastModified'],
                    'ContentType' => $result['ContentType'],
                    'StorageClass' => $result['StorageClass'],
                ];
            }else {
                return false;
            }
        } catch (ObsException $exception) {
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
        $meta = $this->getMetadata($path);

        return isset($meta['Size']) ? ['size' => $meta['Size']] : false;
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
        $meta = $this->getMetadata($path);

        return isset($meta['ContentType']) ? ['mimetype' => $meta['ContentType']] : false;
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
        $meta = $this->getMetadata($path);
        return isset($meta['LastModified']) ? ['timestamp' => strtotime($meta['LastModified'])] : false;
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

        try {
            $meta = $this->client->getObjectAcl(
                [
                    'Bucket' => $this->bucket,
                    'Key' => $path,
                ]
            );

            foreach ($meta['Grants'] as $grant) {
                if (isset($grant['Grantee']['URI'])
                    && $grant['Permission'] === ObsClient::PermissionRead
                    && strpos($grant['Grantee']['URI'], ObsClient::AllUsers) !== false
                ) {
                    return ['visibility' => AdapterInterface::VISIBILITY_PUBLIC];
                }
            }

            return ['visibility' => AdapterInterface::VISIBILITY_PRIVATE];

        } catch (ObsException $exception) {
            return false;
        }
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
            'timestamp' => $meta['LastModified'],
            'size' => $meta['Size'],
            'mimetype' => $meta['ContentType'],
            'md5'   => $meta['eTag'],
        ];
    }

    /**
     * Read an object from the ObsClient.
     */
    protected function getObject(string $path): StreamInterface
    {
        $path = $this->applyPathPrefix($path);

        try {
            $model = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);

            return $model['Body'];
        } catch (ObsException $exception) {
            throw $exception;
        }
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