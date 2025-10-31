<?php
namespace app\admin\controller\doc;

class DocParser
{
    private $params = array ();

    /**
     * 解析注释
     * @param string $doc
     * @return array
     */
    public function parse($doc = '') {
        if ($doc == '') {
            return $this->params;
        }
        // Get the comment
        if (preg_match ( '#^/\*\*(.*)\*/#s', $doc, $comment ) === false)
            return $this->params;
        $comment = trim ( $comment [1] );
        // Get all the lines and strip the * from the first character
        if (preg_match_all ( '#^\s*\*(.*)#m', $comment, $lines ) === false)
            return $this->params;
        $this->parseLines ( $lines [1] );
        return $this->params;
    }
    
    private function parseLines($lines) {
        $desc = [];
        foreach ( $lines as $line ) {
            $parsedLine = $this->parseLine ( $line ); // Parse the line

            if ($parsedLine === false && ! isset ( $this->params ['description'] )) {
                if (isset ( $desc )) {
                    // Store the first line in the short description
                    $this->params ['description'] = implode ( PHP_EOL, $desc );
                }
                $desc = array ();
            } elseif ($parsedLine !== false) {
                $desc [] = $parsedLine; // Store the line in the long description
            }
        }
        $desc = implode ( ' ', $desc );
        if (! empty ( $desc ))
            $this->params ['long_description'] = $desc;
    }
    
    private function parseLine($line) {
        // trim the whitespace from the line
        $line = trim ( $line );

        if (empty ( $line ))
            return false; // Empty line

        if (strpos ( $line, '@' ) === 0) {
            if (strpos ( $line, ' ' ) > 0) {
                // Get the parameter name
                $param = substr ( $line, 1, strpos ( $line, ' ' ) - 1 );
                $value = substr ( $line, strlen ( $param ) + 2 ); // Get the value
            } else {
                $param = substr ( $line, 1 );
                $value = '';
            }
            // Parse the line and return false if the parameter is valid
            if ($this->setParam ( $param, $value ))
                return false;
        }

        return $line;
    }
    
    private function setParam($param, $value) {
        if ($param == 'param' || $param == 'header')
            $value = $this->formatParam( $value );
        if ($param == 'class')
            list ( $param, $value ) = $this->formatClass ( $value );

        if($param == 'return' || $param == 'param' || $param == 'header'){
            $this->params [$param][] = $value;
        }else if (empty ( $this->params [$param] )) {
            $this->params [$param] = $value;
        } else {
            $this->params [$param] = $this->params [$param] . $value;
        }
        return true;
    }
    
    private function formatClass($value) {
        $r = preg_split ( "[\(|\)]", $value );
        if (is_array ( $r )) {
            $param = $r [0];
            parse_str ( $r [1], $value );
            foreach ( $value as $key => $val ) {
                $val = explode ( ',', $val );
                if (count ( $val ) > 1)
                    $value [$key] = $val;
            }
        } else {
            $param = 'Unknown';
        }
        return array (
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


    private function getParamType($type){
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
        return array_key_exists($type,$typeMaps) ? $typeMaps[$type] : $type;
    }
}