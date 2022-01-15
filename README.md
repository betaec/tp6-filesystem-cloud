# tp6-filesystem-cloud
ThinkPHP6 的Filesystem扩展包，支持上传到阿里云、腾讯云、七牛云、华为云

## 使用方法

### 安装

```php
composer require johnnycai/tp6-filesystem-cloud
```

### 在config/filesystem.php中增加对应驱动配置

```php
return [
    "default" => "oss",
    "disks" => [
        "public" => [
            "type" => "local",
            "root" => ".",
            "visibility" => "public",
            "domain" => ""
        ],
        // 阿里云配置
        "oss" => [
            "type" => "oss",
            'prefix'  => '',// 前缀，非必填
            "accessKeyId" => "",
            "accessKeySecret" => "",
            "endpoint" => "",
            "bucket" => "",
            "domain" => ""
        ],
        // 七牛云配置
        "qiniu" => [
            "type" => "qiniu",
            "accessKey" => "",
            "secretKey" => "",
            "bucket" => "",
            "domain" => ""
        ],
        // 腾讯云配置
        "cos" => [
            "type" => "cos",
            "region" => "ap-guangzhou",
            "credentials" => [
                "appId" => "",
                "secretId" => "",
                "secretKey" => ""
            ],
            "bucket" => "",
            "domain" => "",
            "scheme" => "https",
            'encrypt'=> false,
        ],
        // 华为云配置
        "obs" => [
            "type" => "obs",
            "accessKey" => "",
            "secretKey" => "",
            "endpoint" => "",
            "bucket" => "",
            "domain" => ""
        ],
    ]
];
```
## 用法

### 上传
```php
$file = $this->request->file('file');
\think\facade\Filesystem::disk('oss')->putFile('upload', $file);
```
### 删除
```php
\think\facade\Filesystem::disk('oss')->delete($path);
```

### 更新
```php
\think\facade\Filesystem::disk('oss')->update($path);
```

### 重命名
```php
\think\facade\Filesystem::disk('oss')->rename($path，$newpath);
```

### 创建文件夹
```php
\think\facade\Filesystem::disk('oss')->createDir($dirname);
```

### 删除文件夹
```php
\think\facade\Filesystem::disk('oss')->createDir($dirname);
```

###获取链接
```php
\think\facade\Filesystem::disk('oss')->getUrl($path);
```

###读取文件
```php
\think\facade\Filesystem::disk('oss')->getUrl(read);
```
更详细用法参考Adapter对应文件