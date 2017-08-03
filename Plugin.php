<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * 七牛云存储插件
 * 
 * @package PutToQiNiu
 * @author Seaslaugh
 * @version 1.0
 * @link http://www.seaslaugh.com
 */
class PutToQiNiu_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 用户配置信息
     * @var array
     */
    private static $options;

    /**
     * 七牛认证类
     * @var \Qiniu\Auth
     */
    private static $QiNiuAuth;

    /**
     * 七牛上传类
     * @var \Qiniu\Storage\UploadManager
     */
    private static $QiNiuUpload;

    /**
     * 七牛bucket管理类
     * @var \Qiniu\Storage\BucketManager
     */
    private static $QiNiuBucketMar;

    /**
     * 激活插件
     * 
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        $handles = [
            'uploadHandle', // 上传
            'modifyHandle', // 更改
            'deleteHandle', // 删除
            'attachmentDataHandle',// 获取文件路径
            'attachmentHandle' // 获取文件内容
        ];
        foreach ($handles as $handle) {
            Typecho_Plugin::factory('Widget_Upload')->$handle = array(__CLASS__, $handle);
        }
        return _t('七牛云存储插件已激活，请进行空间信息配置！');
    }
    
    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     * 
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate(){
        return _t('七牛云存储插件已被禁用！');
    }
    
    /**
     * 获取插件配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $configs = [
            [
                'field' => 'accessKey',
                'rules' => [
                    ['rule' => 'required','msg' => '请填写AccessKey']
                ],
                'form' => 'AccessKey'
            ],
            [
                'field' => 'secretKey',
                'rules' =>  [
                    ['rule' => 'required','msg' => '请填写SecretKey']
                ],
                'form' => 'SecretKey'
            ],
            [
                'field' => 'bucket',
                'rules' =>  [
                    ['rule' =>'required','msg' => '请填写Bucket']
                ],
                'form' => 'Bucket'
            ],
            [
                'field' => 'domain',
                'rules' =>  [
                    ['rule' =>'required','msg' => '请填写绑定域名'],
                    ['rule' =>'url','msg' => '域名格式不正确']
                ],
                'form' => '绑定域名'
            ],
        ];
        foreach ($configs as $config) {
            $input = new Typecho_Widget_Helper_Form_Element_Text($config['field'],NULL,NULL, _t($config['form']));
            foreach ($config['rules'] as $rule) {
                $input->addRule($rule['rule'], _t($rule['msg']));
            }
            $form->addInput($input);
        }
    }
    
    /**
     * 个人用户的配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form){}

    /**
     * 上传文件
     *
     * @access public
     * @param $file
     * @return array|bool
     */
    public static function uploadHandle($file)
    {
        if (empty($file['name'])) {
            return false;
        }

        $ext = self::getSafeName($file['name']);

        if (!Widget_Upload::checkFileType($ext) || Typecho_Common::isAppEngine()) {
            return false;
        }

        if (empty($file['tmp_name'])) {
            return false;
        }

        if (!isset($file['size'])) {
            $file['size'] = filesize($file['tmp_name']);
        }

        self::initialize();

        $token = self::$QiNiuAuth->uploadToken(self::$options->bucket);
        $fileName =  sprintf('%u', crc32(uniqid())) . '.' . $ext;
        list($ret, $err) = self::$QiNiuUpload->putFile($token, $fileName, $file['tmp_name']);
        if ($err !== null) {
            return false;
        }

        $mime = Typecho_Common::mimeContentType($file['tmp_name']);
        unlink($file['tmp_name']);

        return array(
            'name' => $file['name'],
            'path' => $ret['key'],
            'size' => $file['size'],
            'type' => $ext,
            'mime' => $mime,
        );
    }

    /**
     * 修改文件
     * @param $oldFile array 旧文件
     * @param $newFile array 新文件
     */
    public static function modifyHandle($oldFile , $newFile)
    {
        if (empty($newFile['name'])) {
            return false;
        }

        $ext = self::getSafeName($newFile['name']);

        if ($oldFile['attachment']->type != $ext || Typecho_Common::isAppEngine()) {
            return false;
        }

        if (isset($newFile['tmp_name'])) {
            $deleted = self::deleteHandle($oldFile);
            if (!$deleted) {
                return false;
            }
            self::initialize();
            $token = self::$QiNiuAuth->uploadToken(self::$options->bucket);
            $oldPath =$oldFile['attachment']->path;
            list($ret, $err) = self::$QiNiuUpload->putFile($token, $oldPath, $newFile['tmp_name']);
            if ($err !== null) {
                return false;
            }
        }else {
            return false;
        }

        if (!isset($newFile['size'])) {
            $newFile['size'] = filesize($newFile['path']);
        }

        //返回相对存储路径
        return array(
            'name' => $oldFile['attachment']->name,
            'path' =>$oldFile['attachment']->path,
            'size' => $newFile['size'],
            'type' => $oldFile['attachment']->type,
            'mime' => $oldFile['attachment']->mime
        );
    }

    /**
     * 删除文件
     *
     * @access public
     * @param array $content 文件相关信息
     * @return boolean
     */
    public static function deleteHandle(array $content)
    {
        self::initialize('delete');
        $err = self::$QiNiuBucketMar->delete(self::$options->bucket, $content['attachment']->path);
        return $err === null;
    }

    /**
     * 获取实际文件绝对访问路径
     *
     * @access public
     * @param array $content 文件相关信息
     * @return string
     */
    public static function attachmentHandle(array $content)
    {
        self::setOptions();
        return Typecho_Common::url($content['attachment']->path,self::$options->domain);
    }

    /**
     * 获取实际文件数据
     *
     * @access public
     * @param array $content 文件相关信息
     * @return string
     */
    public static function attachmentDataHandle(array $content)
    {
        return file_get_contents(self::attachmentHandle($content));
    }

    /**
     * 初始化七牛SDK
     * @static
     * @param  string $operate 操作类型
     * @access private
     */
    private static function initialize($operate = 'upload')
    {
        require_once __DIR__ .DIRECTORY_SEPARATOR .'sdk'.DIRECTORY_SEPARATOR.'autoload.php';
        self::setOptions();
        if (!isset(self::$QiNiuAuth)){
            self::$QiNiuAuth = new \Qiniu\Auth(self::$options->accessKey, self::$options->secretKey);
        }
        if ($operate == 'upload' && !isset(self::$QiNiuUpload)){
            self::$QiNiuUpload = new \Qiniu\Storage\UploadManager();
        }
        if ($operate == 'delete' && !isset(self::$QiNiuUpload)){
            self::$QiNiuBucketMar= new \Qiniu\Storage\BucketManager(self::$QiNiuAuth);
        }
    }

    /**
     * 获取安全的文件名
     *
     * @param string $name
     * @static
     * @access private
     * @return string
     */
    private static function getSafeName(&$name)
    {
        $name = str_replace(array('"', '<', '>'), '', $name);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
        $info = pathinfo($name);
        $name = substr($info['basename'], 1);

        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }

    /**
     * 获取并设置options
     *
     * @static
     * @access private
     */
    private static function setOptions()
    {
        isset(self::$options) OR self::$options = Typecho_Widget::widget('Widget_Options')->plugin('PutToQiNiu');
    }

}
