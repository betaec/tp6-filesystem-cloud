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

use GuzzleHttp\Client as HttpClient;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use Qcloud\Cos\Client;
use Qcloud\Cos\Exception\ServiceResponseException;

/**
 * Class CosAdapter
 * @author johnny <johnnycaimail@yeah.net>
 */
class CosAdapter extends AbstractAdapter
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var \GuzzleHttp\Client
     */
    protected $httpClient;

    /**
     * @var array
     */
    protected $config;

    /**
     * CosAdapter constructor.
     *
     * @param array $config
     */
    public function __construct(array $config = []) {
        $this->config        = $config;
        $this->config['cdn'] = $this->config['domain'];
        if (!empty($this->config['cdn'])) {
            $this->setPathPrefix($this->config['cdn']);
        }
        $this->config['timeout']         = 60;
        $this->config['connect_timeout'] = 60;
        $this->config['read_from_cdn']   = false;
        $this->client = new Client($this->config);
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
            return $this->client->upload(
                $this->getBucketWithAppId(),
                $path,
                $contents,
                $this->prepareUploadConfig($config)
            );
        } catch (ServiceResponseException $e) {
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

            $result = $this->client->upload(
                $this->getBucketWithAppId(),
                $path,
                $contents,
                $this->prepareUploadConfig($config)
            );

            if (is_resource($resource)) {
                fclose($resource);
            }

            return $result;

        } catch (ServiceResponseException $e) {
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
            if ($result = $this->copy($path, $newpath)) {
                $this->delete($path);
            }

            return $result;
        } catch (ServiceResponseException $e) {
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
            return (bool) $this->client->copyObject([
                'Bucket'     => $this->getBucketWithAppId(),
                'Key'        => $newpath,
                'CopySource' => $this->getSourcePath($path),
            ]);
        } catch (ServiceResponseException $e) {
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
            return (bool) $this->client->deleteObject([
                'Bucket' => $this->getBucketWithAppId(),
                'Key'    => $path,
            ]);
        } catch (ServiceResponseException $e) {
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
        try {
            $response = $this->listDirObjects($dirname);
            if (empty($response['Contents'])) {
                return true;
            }

            $keys = array_map(function ($item) {
                return ['Key' => $item['Key']];
            }, (array)$response['Contents']);

            return (bool) $this->client->deleteObject([
                'Bucket' => $this->getBucketWithAppId(),
                'Key'    => $keys,
            ]);
        } catch (ServiceResponseException $e) {
            return false;
        }
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
            return $this->client->putObject([
                'Bucket' => $this->getBucketWithAppId(),
                'Key'    => $dirname.'/',
                'Body'   => '',
            ]);
        } catch (ServiceResponseException $e) {
            return false;
        }
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
        } catch (ServiceResponseException $e) {
            return false;
        }
    }

    /**
     * getUrl
     *
     * @param string $path
     * @return string
     */
    public function getUrl($path) {
        if (!empty($this->config['cdn'])) {
            return $this->applyPathPrefix($path);
        }
        $options = [
            'Scheme' => $this->config['scheme'] ?? 'http',
        ];
        return $this->client->getObjectUrl(
            $this->getBucket(), $path, null, $options
        );
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
        try {
            return (bool) $this->client->putObjectAcl([
                'Bucket' => $this->getBucketWithAppId(),
                'Key'    => $path,
                'ACL'    => $this->normalizeVisibility($visibility),
            ]);
        } catch (ServiceResponseException $e) {
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
        try {
            if ($this->config['read_from_cdn']) {
                $response = $this->getHttpClient()
                    ->get($this->getTemporaryUrl($path, date('+5 min')))
                    ->getBody()
                    ->getContents();
            } else {
                $response = $this->client->getObject([
                    'Bucket' => $this->getBucket(),
                    'Key'    => $path,
                ])['Body'];
            }
            return ['contents' => (string)$response];
        } catch (ServiceResponseException $e) {
            return false;
        }
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
            $temporaryUrl = $this->getTemporaryUrl($path, \strtotime('+5 min'));
            $stream       = $this->getHttpClient()
                ->get($temporaryUrl, ['stream' => true])
                ->getBody()
                ->detach();
            return ['stream' => $stream];
        } catch (ServiceResponseException $e) {
            return false;
        }
    }

    /**
     * File list core method.
     * 单次调用 listObjects 接口一次只能查询1000个对象，如需要查询所有的对象，则需要循环调用。
     * @param string $dirname
     * @param bool   $recursive
     * @return array
     */
    public function listDirObjects($directory = '', $recursive = false)
    {
        $nextMarker = '';
        $list = [];
        $directory = ('' === (string)$directory) ? '' : ($directory . '/');

        while (true) {
            $response = $this->client->listObjects([
                'Bucket'    => $this->getBucketWithAppId(),
                'Prefix'    => $directory,
                'Delimiter' => $recursive ? '' : '/',
                'Marker'    => $nextMarker,
                'MaxKeys'   => 1000,//设置单次查询打印的最大数量，最大为1000
            ]);

            foreach ((array) $response['Contents'] as $content) {
                $list[] = $this->normalizeFileInfo($content);
            }

            if (!$response['IsTruncated']) {
                break;
            }
            $nextMarker = $response['NextMarker'] ?: '';
        }

        return $list;
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
        $directory = '/' == substr($directory, -1) ? $directory : $directory.'/';

        return $this->listDirObjects($directory,$recursive);
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
        try {
            return $this->client->headObject([
                'Bucket' => $this->getBucketWithAppId(),
                'Key'    => $path,
            ]);
        } catch (ServiceResponseException $e) {
            return false;
        }
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
        return isset($meta['ContentLength']) ? ['size' => $meta['ContentLength']] : false;
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
        try {
            $meta = $this->client->getObjectAcl([
                'Bucket' => $this->getBucketWithAppId(),
                'Key'    => $path,
            ]);

            foreach ($meta['Grants'] as $grant) {
                if (isset($grant['Grantee']['URI'])
                    && $grant['Permission'] === 'READ'
                    && strpos($grant['Grantee']['URI'], 'global/AllUsers') !== false
                ) {
                    return ['visibility' => AdapterInterface::VISIBILITY_PUBLIC];
                }
            }

            return ['visibility' => AdapterInterface::VISIBILITY_PRIVATE];
        } catch (ServiceResponseException $e) {
            return false;
        }
    }

    /**
     * getTemporaryUrl
     *
     * @param string $path
     * @param string|int $expiration
     * @param array $options
     * @return string
     */
    public function getTemporaryUrl($path, $expiration, array $options = []) {
        $options    = array_merge($options, ['Scheme' => $this->config['scheme'] ?? 'http']);
        $expiration = date('c', !\is_numeric($expiration) ? \strtotime($expiration) : \intval($expiration));
        $objectUrl  = $this->client->getObjectUrl(
            $this->getBucket(), $path, $expiration, $options
        );
        $url        = parse_url($objectUrl);
        if ($this->config['cdn']) {
            return \sprintf('%s/%s?%s', \rtrim($this->config['cdn'], '/'), urldecode($url['path']), $url['query']);
        }
        return $objectUrl;
    }

    /**
     * getBucket
     * @return string
     */
    protected function getBucket()
    {
        return $this->config['bucket'];
    }

    /**
     * getAppId
     * @return string
     */
    protected function getAppId() {
        return $this->config['credentials']['appId'] ?? null;
    }

    /**
     * getBucketWithAppId
     * @return string
     */
    protected function getBucketWithAppId()
    {
        return $this->getBucket().'-'.$this->getAppId();
    }

    /**
     * getRegion
     * @return string
     */
    protected function getRegion() {
        return $this->config['region'] ?? '';
    }

    /**
     * getHttpClient
     * @return \GuzzleHttp\Client
     */
    public function getHttpClient() {
        return $this->httpClient ?: $this->httpClient = new HttpClient();
    }

    /**
     * getSourcePath
     * @param string $path
     * @return string
     */
    protected function getSourcePath($path) {
        return sprintf('%s.cos.%s.myqcloud.com/%s',
            $this->getBucket(), $this->getRegion(), $path
        );
    }

    /**
     * @param $path
     *
     * @return string
     */
    protected function getPicturePath($path)
    {
        return sprintf('%s.pic.%s.myqcloud.com/%s',
            $this->getBucketWithAppId(), $this->getRegion(), $path
        );
    }

    /**
     * prepareUploadConfig
     * @param Config $config
     * @return array
     */
    protected function prepareUploadConfig(Config $config) {
        $options = [];

        if (isset($this->config['encrypt']) && $this->config['encrypt']) {
            $options['ServerSideEncryption'] = 'AES256';
        }

        if ($config->has('params')) {
            $options['params'] = $config['params'];
        }

        if ($config->has('visibility')) {
            $options['params']['ACL'] = $this->normalizeVisibility($config['visibility']);
        }

        return $options;
    }

    /**
     * normalizeVisibility
     * @param $visibility
     * @return string
     */
    protected function normalizeVisibility($visibility) {
        switch ($visibility) {
            case AdapterInterface::VISIBILITY_PUBLIC:
                $visibility = 'public-read';
                break;
        }
        return $visibility;
    }

    /**
     * normalizeFileInfo
     * @param array $content
     * @return array
     */
    protected function normalizeFileInfo(array $content)
    {
        $path = pathinfo($content['Key']);

        return [
            'type'      => substr($content['Key'], -1) === '/' ? 'dir' : 'file',
            'path'      => $content['Key'],
            'timestamp' => \strtotime($content['LastModified']),
            'size'      => \intval($content['Size']),
            'dirname'   => $path['dirname'] === '.' ? '' : (string) $path['dirname'],
            'basename'  => \strval($path['basename']),
            'filename'  => \strval($path['filename']),
            'extension' => isset($path['extension']) ? $path['extension'] : '',
        ];
    }
}