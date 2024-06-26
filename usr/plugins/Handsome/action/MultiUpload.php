<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * 上传动作
 *
 * @category typecho
 * @package Widget
 * @copyright Copyright (c) 2008 Typecho team (http://www.typecho.org)
 * @license GNU General Public License 2.0
 * @version $Id$
 */

/**
 * 上传组件
 *
 * @author qining
 * @category typecho
 * @package Widget
 */
class Handsome_Upload extends Widget_Abstract_Contents implements Widget_Interface_Do
{
    //上传文件目录
    const UPLOAD_DIR = '/usr/uploads';

    /**
     * 创建上传路径
     *
     * @access private
     * @param string $path 路径
     * @return boolean
     */
    private static function makeUploadDir($path)
    {
        $path = preg_replace("/\\\+/", '/', $path);
        $current = rtrim($path, '/');
        $last = $current;

        while (!is_dir($current) && false !== strpos($path, '/')) {
            $last = $current;
            $current = dirname($current);
        }

        if ($last == $current) {
            return true;
        }

        if (!@mkdir($last)) {
            return false;
        }

        $stat = @stat($last);
        $perms = $stat['mode'] & 0007777;
        @chmod($last, $perms);

        return self::makeUploadDir($path);
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
     * 上传文件处理函数,如果需要实现自己的文件哈希或者特殊的文件系统,请在options表里把uploadHandle改成自己的函数
     *
     * @access public
     * @param array $file 上传的文件
     * @return mixed
     */
    public static function uploadHandle($file)
    {
        if (empty($file['name'])) {
            return "需要上传文件名为空";
        } else {
//            print_r($file);
        }

        $result = Typecho_Plugin::factory('Widget_Upload')->trigger($hasUploaded)->uploadHandle($file);
        if ($hasUploaded) {
            return "文件已经上传过";
        }

        $ext = self::getSafeName($file['name']);

//        var_dump($file['name']);
//        print_r($ext);

        if (!self::checkFileType($ext) || Typecho_Common::isAppEngine()) {
            return "文件类型不支持";
        }

        $date = new Typecho_Date();
        $path = Typecho_Common::url(defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : self::UPLOAD_DIR,
                defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__)
            . '/' . $date->year . '/' . $date->month;

        //创建上传目录
        if (!is_dir($path)) {
            if (!self::makeUploadDir($path)) {
                return "创建upload文件夹失败，检查权限";
            }
        }

        //获取文件名
        $fileName = sprintf('%u', crc32(uniqid())) . '.' . $ext;
        $path = $path . '/' . $fileName;

        if (isset($file['tmp_name'])) {

            //移动上传文件
            if (!@move_uploaded_file($file['tmp_name'], $path)) {
                return "移动临时文件失败，检查权限";
            }
        } else if (isset($file['bytes'])) {

            //直接写入文件
            if (!file_put_contents($path, $file['bytes'])) {
                return "写入文件失败，检查权限";
            }
        } else {
            return "文件大小为空";
        }

        if (!isset($file['size'])) {
            $file['size'] = filesize($path);
        }

        //返回相对存储路径
        return array(
            'name' => $file['name'],
            'path' => (defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : self::UPLOAD_DIR)
                . '/' . $date->year . '/' . $date->month . '/' . $fileName,
            'size' => $file['size'],
            'type' => $ext,
            'mime' => Typecho_Common::mimeContentType($path)
        );
    }

    /**
     * 修改文件处理函数,如果需要实现自己的文件哈希或者特殊的文件系统,请在options表里把modifyHandle改成自己的函数
     *
     * @access public
     * @param array $content 老文件
     * @param array $file 新上传的文件
     * @return mixed
     */
    public static function modifyHandle($content, $file)
    {
        if (empty($file['name'])) {
            return false;
        }

        $result = Typecho_Plugin::factory('Widget_Upload')->trigger($hasModified)->modifyHandle($content, $file);
        if ($hasModified) {
            return $result;
        }

        $ext = self::getSafeName($file['name']);

        if ($content['attachment']->type != $ext || Typecho_Common::isAppEngine()) {
            return false;
        }

        $path = Typecho_Common::url($content['attachment']->path,
            defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__);
        $dir = dirname($path);

        //创建上传目录
        if (!is_dir($dir)) {
            if (!self::makeUploadDir($dir)) {
                return false;
            }
        }

        if (isset($file['tmp_name'])) {

            @unlink($path);

            //移动上传文件
            if (!@move_uploaded_file($file['tmp_name'], $path)) {
                return false;
            }
        } else if (isset($file['bytes'])) {

            @unlink($path);

            //直接写入文件
            if (!file_put_contents($path, $file['bytes'])) {
                return false;
            }
        } else {
            return false;
        }

        if (!isset($file['size'])) {
            $file['size'] = filesize($path);
        }

        //返回相对存储路径
        return array(
            'name' => $content['attachment']->name,
            'path' => $content['attachment']->path,
            'size' => $file['size'],
            'type' => $content['attachment']->type,
            'mime' => $content['attachment']->mime
        );
    }

    /**
     * 删除文件
     *
     * @access public
     * @param array $content 文件相关信息
     * @return string
     */
    public static function deleteHandle(array $content)
    {
        $result = Typecho_Plugin::factory('Widget_Upload')->trigger($hasDeleted)->deleteHandle($content);
        if ($hasDeleted) {
            return $result;
        }

        return !Typecho_Common::isAppEngine()
            && @unlink(__TYPECHO_ROOT_DIR__ . '/' . $content['attachment']->path);
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
        $result = Typecho_Plugin::factory('Widget_Upload')->trigger($hasPlugged)->attachmentHandle($content);
        if ($hasPlugged) {
            return $result;
        }

        $options = Typecho_Widget::widget('Widget_Options');
        return Typecho_Common::url($content['attachment']->path,
            defined('__TYPECHO_UPLOAD_URL__') ? __TYPECHO_UPLOAD_URL__ : $options->siteUrl);
    }

    /**
     * 获取实际文件数据
     *
     * @access public
     * @param array $content
     * @return string
     */
    public static function attachmentDataHandle(array $content)
    {
        $result = Typecho_Plugin::factory('Widget_Upload')->trigger($hasPlugged)->attachmentDataHandle($content);
        if ($hasPlugged) {
            return $result;
        }

        return file_get_contents(Typecho_Common::url($content['attachment']->path,
            defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__));
    }

    /**
     * 检查文件名
     *
     * @access private
     * @param string $ext 扩展名
     * @return boolean
     */
    public static function checkFileType($ext)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        return in_array($ext, $options->allowedAttachmentTypes);
    }


    public static function getDataFromWebUrl($url)
    {
        $file_contents = "";
        if (function_exists('file_get_contents')) {
            $file_contents = @file_get_contents($url);
        }
        if ($file_contents == "") {
            $ch = curl_init();
            $timeout = 30;
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            $file_contents = curl_exec($ch);
            curl_close($ch);
        }
        return $file_contents;
    }


    /**
     * 执行升级程序
     *
     * @access public
     * @return void
     */
    public function upload()
    {
        $uploadType = "file";
        $original = "";
        if (!empty($_FILES)) {
            $file = array_pop($_FILES);
            if (is_array($file["error"])) {//处理传过来的是一个file数组
                $file = array(
                    "name" => $file["name"][0],
                    "type" => $file["type"][0],
                    "error" => $file["error"][0],
                    "tmp_name" => $file["tmp_name"][0],
                    "size" => $file["size"][0],
                );
            }

            $phpMaxFilesize = function_exists('ini_get') ? trim(ini_get('upload_max_filesize')) : 0;
            if (!@$file["size"]){
                $this->response->setStatus(500)->throwContent("文件大小超过服务器配置大小 upload_max_filesize：".$phpMaxFilesize);
            }
        } else {
            $post = json_decode(file_get_contents("php://input"), true);
//            print_r($post);
            if (@!empty($post["url"])) {
                $imageUrl = $post["url"];
                $original = $imageUrl;
                if (substr($imageUrl, 0, 4) != "http") {//图片地址没有http前缀
                    if (substr($imageUrl,0,2) =="//"){//图片地址是相对路径
                        $imageUrl = "http:".$imageUrl;
                    }else{
                        $imageUrl = "";
                    }
                }
//                print_r("imageUrl".$imageUrl);
                if ($imageUrl!=""){
                    $ret = parse_url($imageUrl);
                    // 如果不是外链，直接返回false
                    $options = Typecho_Widget::widget('Widget_Options');
                    if (strpos($options->siteUrl, @$ret["host"])!==false || strpos($options->rootUrl, @$ret["host"])!==false){
                        $this->response->throwJson(array(
                            "msg" => "it is uploaded, need do nothing",
                            "code" => 100,
                            "data" => array(
                                'cid' => "",
                                "title" => "",
                                'type' => "",
                                'size' => "",
                                'bytes' => "",
                                'isImage' => false,
                                "url" =>"",
                                'permalink' => "",
                                "originalURL"=>""

                            )
                        ));
                        return ;
                    }
                    $fileName = mb_split("/", @$ret["path"]);
                    $fileName = $fileName[count($fileName) - 1];
                    //todo 智能识别后缀，目前是根据链接地址来的
                    if (strpos(@$ret["query"],"webp")!==false && strpos($fileName,".")===false){
                        $fileName.=".webp";
                    }
                    if (strpos($fileName,".jpg")!==false){
                        $fileName.=".jpg";
                    }else if (strpos($fileName,".jpeg")!==false){
                        $fileName.=".jpeg";
                    } else if (strpos($fileName,".png")!==false){
                        $fileName.=".png";
                    }else {
                        // 降级使用jpg上传
                        $fileName.=".jpg";
                    }
                    $file = array(
                        "name" => $fileName,
                        "error" => 0,
                        "bytes" => self::getDataFromWebUrl($imageUrl),
                    );
                    $uploadType = "web";
//                    print_r($file);
                }else{
                    $this->response->throwJson(array(
                        "msg" => "图片外链没有http协议头",
                        "code" => 100,
                        "data" => array(
                            'cid' => "",
                            "title" => "",
                            'type' => "",
                            'size' => "",
                            'bytes' => "",
                            'isImage' => false,
                            "url" =>"",
                            'permalink' => "",
                            "originalURL"=>""

                        )
                    ));
                    return ;
                }

            } else {
                //不需要处理
                $this->response->throwJson(array(
                    "msg" => "图片外链格式不正确",
                    "code" => 100,
                    "data" => array(
                        'cid' => "",
                        "title" => "",
                        'type' => "",
                        'size' => "",
                        'bytes' => "",
                        'isImage' => false,
                        "url" =>"",
                        'permalink' => "",
                        "originalURL"=>""

                    )
                ));
                return;
            }
        }


        if (!empty($file)) {
            if (0 == $file['error'] && ((isset($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) || isset
                    ($file["bytes"]))) {
                // xhr的send无法支持utf8
                if ($this->request->isAjax()) {
                    $file['name'] = urldecode($file['name']);
                }
                //todo
                $result = self::uploadHandle($file);

                if (is_array($result)) {
                    $this->pluginHandle()->beforeUpload($result);

                    $struct = array(
                        'title' => $result['name'],
                        'slug' => $result['name'],
                        'type' => 'attachment',
                        'status' => 'publish',
                        'text' => serialize($result),
                        'allowComment' => 1,
                        'allowPing' => 0,
                        'allowFeed' => 1
                    );

                    if (isset($this->request->cid)) {
                        $cid = $this->request->filter('int')->cid;

                        if ($this->isWriteable($this->db->sql()->where('cid = ?', $cid))) {
                            $struct['parent'] = $cid;
                        }
                    }

                    $insertId = $this->insert($struct);

                    $this->db->fetchRow($this->select()->where('table.contents.cid = ?', $insertId)
                        ->where('table.contents.type = ?', 'attachment'), array($this, 'push'));

                    /** 增加插件接口 */
                    $this->pluginHandle()->upload($this);


                    if ($uploadType == "file") {
                        $this->response->throwJson(array($this->attachment->url, array(
                            'cid' => $insertId,
                            'title' => $this->attachment->name,
                            'type' => $this->attachment->type,
                            'size' => $this->attachment->size,
                            'bytes' => number_format(ceil($this->attachment->size / 1024)) . ' Kb',
                            'isImage' => $this->attachment->isImage,
                            'url' => $this->attachment->url,
                            'permalink' => $this->permalink
                        )));
                    } else {
                        $this->response->throwJson(array(
                                "msg" => "url image upload success",
                                "code" => 0,
                                "data" => array(
                                    'cid' => $insertId,
                                    "title" => $this->attachment->name,
                                    'type' => $this->attachment->type,
                                    'size' => $this->attachment->size,
                                    'bytes' => number_format(ceil($this->attachment->size / 1024)) . ' Kb',
                                    'isImage' => $this->attachment->isImage,
                                    "url" => $this->attachment->url,
                                    'permalink' => $this->permalink,
                                    "originalURL"=>$original,

                                )
                            )
                        );
                    }
                }else{
                    $this->response->setStatus(500)->throwContent("上传文件失败，原因". $result);
                }
            }else{
                $this->response->setStatus(500)->throwContent("上传失败，请尝试使用typecho自带附件上传查看具体错误原因");
            }
        }else{
            // 文件是空的
            $this->response->setStatus(500)->throwContent("上传失败，需要上传的文件为空");
        }

    }

    /**
     * 执行升级程序
     *
     * @access public
     * @return void
     */
    public function modify()
    {
        if (!empty($_FILES)) {
            $file = array_pop($_FILES);
            if (0 == $file['error'] && is_uploaded_file($file['tmp_name'])) {
                $this->db->fetchRow($this->select()->where('table.contents.cid = ?', $this->request->filter('int')->cid)
                    ->where('table.contents.type = ?', 'attachment'), array($this, 'push'));

                if (!$this->have()) {
                    $this->response->setStatus(404);
                    exit;
                }

                if (!$this->allow('edit')) {
                    $this->response->setStatus(403);
                    exit;
                }

                // xhr的send无法支持utf8
                if ($this->request->isAjax()) {
                    $file['name'] = urldecode($file['name']);
                }

                $result = self::modifyHandle($this->row, $file);

                if (false !== $result) {
                    $this->pluginHandle()->beforeModify($result);

                    $this->update(array(
                        'text' => serialize($result)
                    ), $this->db->sql()->where('cid = ?', $this->cid));

                    $this->db->fetchRow($this->select()->where('table.contents.cid = ?', $this->cid)
                        ->where('table.contents.type = ?', 'attachment'), array($this, 'push'));

                    /** 增加插件接口 */
                    $this->pluginHandle()->modify($this);

                    $this->response->throwJson(array($this->attachment->url, array(
                        'cid' => $this->cid,
                        'title' => $this->attachment->name,
                        'type' => $this->attachment->type,
                        'size' => $this->attachment->size,
                        'bytes' => number_format(ceil($this->attachment->size / 1024)) . ' Kb',
                        'isImage' => $this->attachment->isImage,
                        'url' => $this->attachment->url,
                        'permalink' => $this->permalink
                    )));
                }
            }
        }

        $this->response->throwJson(false);
    }

    /**
     * 初始化函数
     *
     * @access public
     * @return void
     */
    public function action()
    {
        if ($this->user->pass('contributor', true) && $this->request->isPost()) {
            if ($this->request->is('do=modify&cid')) {
                $this->security->protect();
                $this->modify();
            } else if ($this->request->is('do=uploadfile')) {
                $this->security->protect();
                $this->upload();
            }else{
                return ;
            }
        }
    }
}
