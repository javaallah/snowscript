<?php
function rpl($string, $search, $replace)
{
    return str_replace($search, $replace, $string);
};
function sjoin($l, $separator = ", ")
{
    return join($l->arr, $separator);
};
function type($guy)
{
    return gettype($guy);
};
function uu($l)
{
    return sjoin($l, '__');
};
function pp($obj, $indent = 0)
{
    foreach ($obj as $k => $v) {
        $type = gettype($v);
        if ((\snow_eq($type, "string") || \snow_eq($type, "integer")) || \snow_eq($type, "NULL")) {
            echo sprintf("%s%s: %s\n", str_repeat(" ", $indent * 2), (string) $k, (string) $v);
        } elseif (\snow_eq($type, "boolean")) {
            echo sprintf("%s%s: %s\n", str_repeat(" ", $indent * 2), (string) $k, $v ? "true" : "false");
        } elseif (\snow_eq($type, "object")) {
            echo sprintf("%s%s - %s\n", str_repeat(" ", $indent * 2), $k, get_class($v));
            pp($v, $indent + 1);
        } elseif (\snow_eq($type, "array")) {
            echo sprintf("%s%s - %s\n", str_repeat(" ", $indent * 2), $k, "array");
            pp($v, $indent + 1);
        } else {
            var_dump($type);
            throw new Exception("Type not implemented: " . $type);
        }
    }
    unset($k, $v);
};
function v($x)
{
    var_dump($x);
};
class Snowscript_Visitors_Scope extends PHPParser_NodeVisitorAbstract
{
    
    public function __construct($ns)
    {
        $this->ns = $ns;
        $this->scopes = snow_list(array(snow_dict(array('names' => snow_dict(array()), 'prefix' => snow_list(array())))));
        $this->in_assign = false;
        $this->global_vars = snow_list(array());
    }
    
    public function scope_has_name($name, $index)
    {
        try {
            return $this->scopes[$index]->names->get($name);
        } catch (IndexError $e) {
            return False;
        }
    }
    
    public function global_name($name)
    {
        if (\snow_eq(count($this->scopes), 1)) {
            return uu(snow_list(array($this->ns, $name)));
        } else {
            return uu(snow_list(array(uu($this->scopes[-1]->prefix), $name)));
        }
    }
    
    public function add_node_to_scope($node, $name, $new_name, $is_global, $global_name)
    {
        if (!$this->scopes[-1]->names->get($name)) {
            $this->scopes[-1]->names[$name] = snow_dict(array('nodes' => snow_list(array()), 'new_name' => null, 'is_global' => null, 'global_name' => $global_name, 'func' => null));
        }
        $this->scopes[-1]->names[$name]->is_global = $is_global;
        $this->scopes[-1]->names[$name]->nodes->append($node);
        $this->rename_nodes($name, $new_name, -1);
        if ($is_global && $this->scopes[-1]->get('func')) {
            $this->scopes[-1]->func->add_global_var($new_name);
            if ($this->scopes[-2]->get('func')) {
                $this->scopes[-2]->func->add_global_var($new_name);
            }
        }
        if ($is_global) {
            $this->global_vars->append($new_name);
        }
    }
    
    public function rename_nodes($name, $new_name, $scope_index)
    {
        $this->scopes[$scope_index]->names[$name]->new_name = $new_name;
        foreach ($this->scopes[$scope_index]->names[$name]->nodes as $node) {
            if (($node instanceof PHPParser_Node_Expr_Assign)) {
                $node->var->name = $new_name;
            } elseif (($node instanceof PHPParser_Node_Stmt_Function)) {
                $node->name = $new_name;
            } elseif (($node instanceof PHPParser_Node_Expr_FuncCall)) {
                $node->name->parts[0] = $new_name;
            } elseif (($node instanceof PHPParser_Node_Expr_Variable)) {
                $node->name = $new_name;
            } else {
                throw new Exception(get_class("Not supported: " . $node));
            }
        }
        unset($node);
    }
    
    public function rename_nodes_all_scopes($name, $new_name)
    {
        foreach ($this->scopes as $k => $scope) {
            if ($scope->names->get($name)) {
                $this->rename_nodes($name, $new_name, $k);
            }
        }
        unset($k, $scope);
    }
    
    public function mark_name_as_global($name, $new_name)
    {
        foreach ($this->scopes as $scope) {
            try {
                $scope->names[$name]->is_global = true;
            } catch (KeyError $e) {
                
            }
        }
        unset($scope);
        $this->global_vars->append($new_name);
    }
    
    public function create_name($node, $name, $allow_redefinition, $allow_creation)
    {
        $is_global = false;
        $global_name = $this->global_name($name);
        if ($this->scope_has_name($name, -2)) {
            if (!$allow_redefinition) {
                throw new Exception("Cant redefine name from outer scope: " . $name);
            }
            $is_global = true;
            $new_name = $this->scopes[-2]->names[$name]->global_name;
            if (!$this->scopes[-2]->names[$name]->is_global) {
                $new_name = $this->scopes[-2]->names[$name]->global_name;
                $this->mark_name_as_global($name, $new_name);
                $this->rename_nodes_all_scopes($name, $new_name);
            }
        } elseif ($this->scopes[-1]->names->get($name)) {
            if (!$allow_redefinition) {
                throw new Exception("Cant redefine name from same scope: " . $name);
            }
            $new_name = $this->scopes[-1]->names[$name]->new_name;
        } else {
            if ($allow_creation) {
                if (\snow_eq(count($this->scopes), 1)) {
                    $new_name = uu(snow_list(array($this->ns, $name)));
                    $is_global = true;
                } else {
                    $new_name = $name;
                }
            } else {
                throw new Exception("Variable doesn't exist: " . $name);
            }
        }
        $this->add_node_to_scope($node, $name, $new_name, $is_global, $global_name);
    }
    
    public function enterNode(PHPParser_Node $node)
    {
        if (($node instanceof PHPParser_Node_Expr_Assign)) {
            $this->create_name($node, $node->var->name, true, true);
            $this->in_assign = true;
        } elseif (($node instanceof PHPParser_Node_Stmt_Function)) {
            $this->create_name($node, $node->name, false, true);
            $this->scopes->append($this->scopes[-1]->copy());
            $this->scopes[-1]->prefix->append($node->name);
            $this->scopes[-1]->func = $node;
        } elseif (($node instanceof PHPParser_Node_Expr_Variable)) {
            if (!$this->in_assign) {
                $this->create_name($node, $node->name, true, false);
            }
        } elseif (($node instanceof PHPParser_Node_Expr_FuncCall)) {
            if (\snow_eq(count($node->name->parts), 1)) {
                $this->create_name($node, $node->name->parts[0], true, false);
                $node->as_variable = true;
            }
        }
    }
    
    public function leaveNode(PHPParser_Node $node)
    {
        if (($node instanceof PHPParser_Node_Stmt_Imports)) {
            return false;
        }
        if (($node instanceof PHPParser_Node_Stmt_Function)) {
            $this->scopes->pop();
        }
        if (($node instanceof PHPParser_Node_Expr_Assign)) {
            $this->in_assign = false;
        }
    }
    
    public function afterTraverse(array $nodes)
    {
        $hmm = snow_list(array());
        if ($this->global_vars) {
            $node = new PHPParser_Node_Stmt_Global($hmm->arr);
            foreach ($this->global_vars as $global_var) {
                $node->vars[$global_var] = new PHPParser_Node_Expr_Variable($global_var);
            }
            unset($global_var);
        }
        array_unshift($nodes, $node);
        return $nodes;
    }
    
    public function add_imports($node)
    {
        $paths = snow_list(array());
        foreach ($node->import_paths as $import_path) {
            $paths[] = $import_path->name;
        }
        unset($import_path);
        $prefix = uu($paths);
        foreach ($node->imports as $imp) {
            $this->scopes[0]['imports'][$imp->name] = uu(snow_list(array($prefix, $imp->name)));
        }
        unset($imp);
    }
}
