<?php
namespace app\admin\controller\doc;

use think\facade\Config;
use think\Request;
use app\admin\controller\BaseController;
use think\facade\View;
use think\facade\Cache;

class DocController extends BaseController
{
    /**
     * @var \think\Request Request实例
     */
    protected $request;

    /**
     * @var Doc
     */
    protected $doc;

    /**
     * @var array 资源类型
     */
    protected $mimeType = [
        'xml'  => 'application/xml,text/xml,application/x-xml',
        'json' => 'application/json,text/x-json,application/jsonrequest,text/json',
        'js'   => 'text/javascript,application/javascript,application/x-javascript',
        'css'  => 'text/css',
        'rss'  => 'application/rss+xml',
        'yaml' => 'application/x-yaml,text/yaml',
        'atom' => 'application/atom+xml',
        'pdf'  => 'application/pdf',
        'text' => 'text/plain',
        'png'  => 'image/png',
        'jpg'  => 'image/jpg,image/jpeg,image/pjpeg',
        'gif'  => 'image/gif',
        'csv'  => 'text/csv',
        'html' => 'text/html,application/xhtml+xml,*/*',
    ];

    public $static_path = '/doc/';

    /**
     * 缓存过期时间（秒）
     */
    protected $cacheExpire = 3600; // 1小时

    /**
     * 缓存标签
     */
    protected $cacheTag = 'apidoc';

    public function __construct(Request $request){

        $this->doc = new Doc((array)Config::get('doc'));
        View::config(['view_path' => __DIR__ . '/view/']);
        View::assign('title', Config::get("doc.title"));
        View::assign('version', Config::get("doc.version"));
        View::assign('copyright', Config::get("doc.copyright"));
        if(Config::get("doc.static_path", '')){
            $this->static_path = Config::get("doc.static_path");
        }
        View::assign('static', $this->static_path);
        $this->request = $request;
        
        // 从配置读取缓存过期时间
        $this->cacheExpire = Config::get('doc.cache_expire', 3600);
    }

    /**
     * 文档首页
     * @return Response
     */
    public function index()
    {
        View::assign('root', $this->request->root());
        if($this->checkLogin() == false){
            return redirect('pass');
        }
        return view('index', ['doc' => $this->request->get('name')]);
    }

    /**
     * 文档搜素
     * @return \think\Response|\think\response\View
     */
    public function search()
    {
        if($this->request->isAjax())
        {
            $query = $this->request->get('query', '');
            $cacheKey = 'doc_search_' . md5($query);
            
            // 尝试从缓存获取
            $data = Cache::get($cacheKey);
            if ($data === false || $data === null) {
                $data = $this->doc->searchList($query);
                // 缓存搜索结果，使用标签
                Cache::tag($this->cacheTag)->set($cacheKey, $data, $this->cacheExpire);
            }
            
            return json($data);
        }
        else
        {
            if($this->checkLogin() == false){
                return redirect('pass');
            }
            
            // 缓存模块列表
            $cacheKey = 'doc_module_list';
            $module = Cache::get($cacheKey);
            if ($module === false || $module === null) {
                $module = $this->doc->getModuleList();
                Cache::tag($this->cacheTag)->set($cacheKey, $module, $this->cacheExpire);
            }
            
            View::assign('root', $this->request->root());
            return view('search', ['module' => $module]);
        }
    }
    
    /**
     * 设置目录树及图标
     * @param $actions
     * @return mixed
     */
    protected function setIcon($actions, $num = 1)
    {
        foreach ($actions as $key=>$moudel){
            if(isset($moudel['actions'])){
                $actions[$key]['iconClose'] = $this->static_path."/js/zTree_v3/img/zt-folder.png";
                $actions[$key]['iconOpen'] = $this->static_path."/js/zTree_v3/img/zt-folder-o.png";
                $actions[$key]['open'] = true;
                $actions[$key]['isParent'] = true;
                $actions[$key]['actions'] = $this->setIcon($moudel['actions'], $num = 1);
            }else{
                $actions[$key]['icon'] = $this->static_path."/js/zTree_v3/img/zt-file.png";
                $actions[$key]['isParent'] = false;
                $actions[$key]['isText'] = true;
            }
        }
        return $actions;
    }

    /**
     * 接口列表
     */
    public function getList()
    {
        $cacheKey = 'doc_list';
        
        // 尝试从缓存获取
        $result = Cache::get($cacheKey);
        if ($result === false || $result === null) {
            $list = $this->doc->getList();
            $list = $this->setIcon($list);
            $result = ['firstId'=>'', 'list'=>$list];
            
            // 缓存列表数据，使用标签
            Cache::tag($this->cacheTag)->set($cacheKey, $result, $this->cacheExpire);
        }
        
        return response($result, 200, [], 'json');
    }

    /**
     * 接口详情
     * @return mixed
     */
    public function getInfo()
    {
        if($this->checkLogin() == false){
            return redirect('pass');
        }
        
        $name = $this->request->get('name');
        if(empty($name)){
            return view('info', ['doc'=>[], 'return'=>[], 'curl_code' => '']);
        }
        
        $cacheKey = 'doc_info_' . md5($name);
        
        // 尝试从缓存获取
        $cachedData = Cache::get($cacheKey);
        if ($cachedData !== false && $cachedData !== null) {
            View::assign('root', $this->request->root());
            return view('info', $cachedData);
        }
        
        list($class, $action) = explode("::", $name);
        $action_doc = $this->doc->getInfo($class, $action);
        
        if($action_doc)
        {
            $return = $this->doc->formatReturn($action_doc);
            $action_doc['header'] = isset($action_doc['header']) ? array_merge($this->doc->__get('public_header'), $action_doc['header']) : [];
            $action_doc['param'] = isset($action_doc['param']) ? array_merge($this->doc->__get('public_param'), $action_doc['param']) : [];
            
            //curl code
            $curl_code = 'curl --location --request '.($action_doc['method'] ?? 'GET');
            $params = [];
            foreach ($action_doc['param'] as $param){
                $params[$param['name']] = $param['default'] ?? '';
            }
            $curl_code .= ' \''.$this->request->root().($action_doc["url"] ?? '').(count($params) > 0 ? '?'.http_build_query($params) : '').'\' ';
            foreach ($action_doc['header'] as $header){
                $curl_code .= '--header \''.$header['name'].':\'';
            }
            
            $viewData = ['doc'=>$action_doc, 'return'=>$return, 'curl_code' => $curl_code];
            
            // 缓存接口详情，使用标签
            Cache::tag($this->cacheTag)->set($cacheKey, $viewData, $this->cacheExpire);
            
            View::assign('root', $this->request->root());
            return view('info', $viewData);
        }
    }

    /**
     * 验证密码
     * @return bool
     */
    protected function checkLogin()
    {
        $pass = $this->doc->__get("password");
        if($pass){
            if(cache('apidoc-pass') === md5($pass)){
                return true;
            }else{
                return false;
            }
        }else{
            return true;
        }
    }

    /**
     * 输入密码
     * @return string
     */
    public function pass()
    {
        View::assign('root', $this->request->root());
        return view('pass');
    }

    /**
     * 登录
     * @return string
     */
    public function login()
    {
        $pass = $this->doc->__get("password");
        if($pass && $this->request->param('pass') === $pass){
            cache('apidoc-pass', md5($pass));
            $data = ['status' => '200', 'message' => '登录成功'];
        }else if(!$pass){
            $data = ['status' => '200', 'message' => '登录成功'];
        }else{
            $data = ['status' => '300', 'message' => '密码错误'];
        }
        return response($data, 200, [], 'json');
    }

    /**
     * 接口访问测试
     * @return \think\Response
     */
    public function debug()
    {
        $data = $this->request->all();
        $api_url = $this->request->param('url');
        $res['status'] = '404';
        $res['message'] = '接口地址无法访问！';
        $res['result'] = '';
        $method = $this->request->param('method_type', 'GET');
        $cookie = $this->request->param('cookie');
        $headers = $this->request->param('header', []);
        
        unset($data['method_type']);
        unset($data['url']);
        unset($data['cookie']);
        unset($data['header']);
        
        $res['result'] = $this->http_request($api_url, $cookie, $data, $method, $headers);
        if($res['result']){
            $res['status'] = '200';
            $res['message'] = 'success';
        }
        return response($res, 200, [], 'json');
    }

    /**
     * curl模拟请求方法
     * @param $url
     * @param $cookie
     * @param array $data
     * @param $method
     * @param array $headers
     * @return mixed
     */
    private function http_request($url, $cookie, $data = array(), $method = array(), $headers = array()){
        $curl = curl_init();
        if(count($data) && $method == "GET"){
            $data = array_filter($data);
            $url .= "?".http_build_query($data);
            $url = str_replace(array('%5B0%5D'), array('[]'), $url);
        }
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        if (count($headers)){
            $head = array();
            foreach ($headers as $name=>$value){
                $head[] = $name.":".$value;
            }
            curl_setopt($curl, CURLOPT_HTTPHEADER, $head);
        }
        $method = strtoupper($method);
        switch($method) {
            case 'GET':
                break;
            case 'POST':
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
                break;
            case 'PUT':
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
                break;
            case 'DELETE':
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }
        if (!empty($cookie)){
            curl_setopt($curl, CURLOPT_COOKIE, $cookie);
        }
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($curl);
        curl_close($curl);
        return $output;
    }
    
    /**
     * 清除所有文档缓存
     * @return \think\Response
     */
    public function clearCache()
    {
        if($this->checkLogin() == false){
            return json(['status' => '403', 'message' => '未授权']);
        }
        
        try {
            // 使用标签清除所有文档相关缓存
            Cache::tag($this->cacheTag)->clear();
            
            return json(['status' => '200', 'message' => '缓存清除成功']);
        } catch (\Exception $e) {
            return json(['status' => '500', 'message' => '缓存清除失败：' . $e->getMessage()]);
        }
    }
    
    /**
     * 获取缓存统计信息
     * @return \think\Response
     */
    public function cacheStats()
    {
        if($this->checkLogin() == false){
            return json(['status' => '403', 'message' => '未授权']);
        }
        
        $stats = [
            'cache_expire' => $this->cacheExpire,
            'cache_tag' => $this->cacheTag,
            'cached_keys' => [
                'doc_list' => Cache::has('doc_list') ? '已缓存' : '未缓存',
                'doc_module_list' => Cache::has('doc_module_list') ? '已缓存' : '未缓存',
            ]
        ];
        
        return json(['status' => '200', 'data' => $stats]);
    }
}
