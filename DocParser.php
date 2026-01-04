<?php
namespace app\admin\controller\doc;

use think\facade\Cache;

class DocParser
{
    private $params = array();
    
    /**
     * 缓存过期时间（秒）
     */
    private $cacheExpire = 7200; // 2小时
    
    /**
     * 缓存标签
     */
    private $cacheTag = 'apidoc_parser';
    
    /**
     * 是否启用缓存
     */
    private $enableCache = true;

    /**
     * 解析注释
     * @param string $doc
     * @param string $cacheKey 可选的缓存键，用于标识不同的文档
     * @return array
     */
    public function parse($doc = '', $cacheKey = '') {
        if ($doc == '') {
            return $this->params;
        }
        
        // 如果启用缓存且提供了缓存键
        if ($this->enableCache && !empty($cacheKey)) {
            $fullCacheKey = 'doc_parse_' . md5($cacheKey . $doc);
            
            // 尝试从缓存获取
            $cachedResult = Cache::get($fullCacheKey);
            if ($cachedResult !== false && $cachedResult !== null) {
                return $cachedResult;
            }
        }
        
        // 重置参数
        $this->params = array();
        
        // Get the comment
        if (preg_match('#^/\*\*(.*)\*/#s', $doc, $comment) === false) {
            return $this->params;
        }
        
        $comment = trim($comment[1]);
        
        // Get all the lines and strip the * from the first character
        if (preg_match_all('#^\s*\*(.*)#m', $comment, $lines) === false) {
            return $this->params;
        }
        
        $this->parseLines($lines[1]);
        
        // 如果启用缓存且提供了缓存键，保存到缓存
        if ($this->enableCache && !empty($cacheKey) && !empty($this->params)) {
            Cache::tag($this->cacheTag)->set($fullCacheKey, $this->params, $this->cacheExpire);
        }
        
        return $this->params;
    }
    
    /**
     * 设置缓存过期时间
     * @param int $seconds
     * @return $this
     */
    public function setCacheExpire($seconds) {
        $this->cacheExpire = $seconds;
        return $this;
    }
    
    /**
     * 启用或禁用缓存
     * @param bool $enable
     * @return $this
     */
    public function setEnableCache($enable) {
        $this->enableCache = $enable;
        return $this;
    }
    
    /**
     * 清除解析器缓存
     * @return bool
     */
    public function clearCache() {
        try {
            Cache::tag($this->cacheTag)->clear();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * 清除特定文档的缓存
     * @param string $cacheKey
     * @param string $doc
     * @return bool
     */
    public function clearDocCache($cacheKey, $doc) {
        if (empty($cacheKey) || empty($doc)) {
            return false;
        }
        
        $fullCacheKey = 'doc_parse_' . md5($cacheKey . $doc);
        return Cache::delete($fullCacheKey);
    }
    
    private function parseLines($lines) {
        $desc = [];
        foreach ($lines as $line) {
            $parsedLine = $this->parseLine($line); // Parse the line

            if ($parsedLine === false && !isset($this->params['description'])) {
                if (isset($desc)) {
                    // Store the first line in the short description
                    $this->params['description'] = implode(PHP_EOL, $desc);
                }
                $desc = array();
            } elseif ($parsedLine !== false) {
                $desc[] = $parsedLine; // Store the line in the long description
            }
        }
        $desc = implode(' ', $desc);
        if (!empty($desc)) {
            $this->params['long_description'] = $desc;
        }
    }
    
    private function parseLine($line) {
        // trim the whitespace from the line
        $line = trim($line);

        if (empty($line)) {
            return false; // Empty line
        }

        if (strpos($line, '@') === 0) {
            if (strpos($line, ' ') > 0) {
                // Get the parameter name
                $param = substr($line, 1, strpos($line, ' ') - 1);
                $value = substr($line, strlen($param) + 2); // Get the value
            } else {
                $param = substr($line, 1);
                $value = '';
            }
            // Parse the line and return false if the parameter is valid
            if ($this->setParam($param, $value)) {
                return false;
            }
        }

        return $line;
    }
    
    private function setParam($param, $value) {
        if ($param == 'param' || $param == 'header') {
            $value = $this->formatParam($value);
        }
        if ($param == 'class') {
            list($param, $value) = $this->formatClass($value);
        }

        if ($param == 'return' || $param == 'param' || $param == 'header') {
            $this->params[$param][] = $value;
        } else if (empty($this->params[$param])) {
            $this->params[$param] = $value;
        } else {
            $this->params[$param] = $this->params[$param] . $value;
        }
        return true;
    }
    
    private function formatClass($value) {
        $r = preg_split("[\(|\)]", $value);
        if (is_array($r)) {
            $param = $r[0];
            parse_str($r[1], $value);
            foreach ($value as $key => $val) {
                $val = explode(',', $val);
                if (count($val) > 1) {
                    $value[$key] = $val;
                }
            }
        } else {
            $param = 'Unknown';
        }
        return array(
            $param,
            $value
        );
    }
    
    private function formatParam($string) {
        $string = trim($string);

        // 正则表达式，用于解析 "类型 变量名 - 描述"
        // (\S+)     : 匹配类型 (如 string, int)
        // \s+       : 匹配空格
        // (\S+)     : 匹配变量名 (如 keywords, status)
        // \s*-\s*   : 匹配一个可选的-，前后可以有空格
        // (.*)      : 匹配后面所有的字符作为描述
        $regex = '/^(\S+)\s+(\S+)\s*-\s*(.*)$/';

        // 正则表达式，用于兼容没有 "-" 的格式 "类型 变量名 描述"
        $regex_no_hyphen = '/^(\S+)\s+(\S+)\s+(.*)$/';

        $param = [];

        if (preg_match($regex, $string, $matches)) {
            // 优先匹配带 "-" 的格式
            $param = [
                'type'    => $matches[1],
                'name'    => $matches[2],
                'desc'    => trim($matches[3]),
                'require' => false, // 默认值
                'default' => '',    // 默认值
            ];
        } elseif (preg_match($regex_no_hyphen, $string, $matches)) {
            // 兼容不带 "-" 的格式
            $param = [
                'type'    => $matches[1],
                'name'    => $matches[2],
                'desc'    => trim($matches[3]),
                'require' => false,
                'default' => '',
            ];
        }

        // 如果成功解析出了一个有效的 param 数组
        if (!empty($param)) {
            // 如果变量名以 $ 开头，则去掉它
            if (strpos($param['name'], '$') === 0) {
                $param['name'] = substr($param['name'], 1);
            }
            return $param;
        }

        // 如果所有解析都失败，返回一个空数组，而不是字符串，以避免后续错误
        return [];
    }

    private function getParamType($type) {
        $typeMaps = [
            'string' => '字符串',
            'int' => '整型',
            'float' => '浮点型',
            'boolean' => '布尔型',
            'date' => '日期',
            'array' => '数组',
            'fixed' => '固定值',
            'enum' => '枚举类型',
            'object' => '对象',
        ];
        return array_key_exists($type, $typeMaps) ? $typeMaps[$type] : $type;
    }
    
    /**
     * 批量解析多个文档
     * @param array $docs 文档数组 ['key' => 'doc_content']
     * @return array
     */
    public function batchParse($docs) {
        $results = [];
        foreach ($docs as $key => $doc) {
            $results[$key] = $this->parse($doc, $key);
        }
        return $results;
    }
    
    /**
     * 获取缓存统计信息
     * @return array
     */
    public function getCacheStats() {
        return [
            'cache_enabled' => $this->enableCache,
            'cache_expire' => $this->cacheExpire,
            'cache_tag' => $this->cacheTag,
        ];
    }
}
