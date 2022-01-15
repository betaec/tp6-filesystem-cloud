<?php
/* +----------------------------------------------------------------------
*  | tp6-filesystem-cloud
*  +----------------------------------------------------------------------
*  | Copyright SileStart [https://silestart.com] (c) 2013-2022 All rights reserved
*  +----------------------------------------------------------------------
*  | Author: Johnny [ johnnycaimail@yeah.net ]
*  +----------------------------------------------------------------------
*/

namespace think\filesystem\driver;

use League\Flysystem\AdapterInterface;
use think\filesystem\Adapter\OssAdapter;
use think\filesystem\Driver;

class Oss extends Driver
{
    protected function createAdapter(): AdapterInterface
    {
        return new OssAdapter($this->config);
    }
}