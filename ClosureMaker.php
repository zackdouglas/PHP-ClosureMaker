<?php
/*******************************************************************************
 * PHP ClosureMaker
 *
 * Authors:: anthony.gallagher@wellspringworldwide.com
 *
 * Copyright:: Copyright 2009, Wellspring Worldwide, LLC Inc. All Rights Reserved.
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 *
 * Description:
 * ===========
 * ClosureMaker enables the use of PHP 5.3-like closures in prior PHP versions.
 * A nice little tool for those of us who don't have the option to upgrade
 * to PHP 5.3 any time soon.
 *
 * Usage:
 * =====
 * Below is an example code:
 *
 * //Declare some variables
 * $a = 5;
 * $b = array('foo' => 'bar');
 *
 * //Create the lambda function. Notice the following:
 * //   1) ClosureMaker uses a PHP 5.3 style of declaring the function
 * //   2) The magic $_ can be used inside your code to access variables
 * //      in the 'environment' of the closure
 * //   3) We pass get_defined_vars() to the closure. This gives us access to
 * //      a snapshot of the current set of declared variables inside the closure
 * $lambda = ClosureMaker::create(
 *  <<<PHP
 *  function (\$x, \$y) {
 *     if (\$x == \$y) {
 *         return \$x;
 *     }
 *     var_dump(\$_['b']);
 *     return \$_['a'];
 * }
 * PHP
 * , get_defined_vars());
 *
 * //Here we redeclare $a and $b for good measure
 * $a = 4;
 * $b = null;
 *
 * echo "\nTest1\n";
 * var_dump($lambda(3, 3));
 * echo "\nTest2\n";
 * var_dump($lambda(4, 1));
 *
 * As expected, this outputs:
 * Test1
 * int(3)
 *
 * Test2
 * array(1) {
 *  ["foo"]=>
 *  string(3) "bar"
 * }
 * int(5)
 *
 * Notice how test two gives us back the values of $a and $b at the time the
 * closure was created.
 ******************************************************************************/

/**
 * Just an error class for closure code
 */
class ClosureMakerException extends Exception {}

/**
 * This class contains all of the information for a single closure
 */
class phpClosure {
    protected $id;
    protected $args;
    protected $body;
    protected $env;
    protected $env_code;
    protected $lambda;

    /**
     * Setup the closure
     *
     * @param <type> $id
     * @param <type> $args
     * @param <type> $body
     * @param <type> $env
     */
    public function __construct($id, $args, $body, $env=array()) {
        $this->id = $id;
        $this->args = $args;
        $this->body = $body;
        $this->env = $env;

        //This is the magic code. This block of code contains a variable
        //   containing a snapshot of the environment at the time of
        //   the creation of the closure. This is prepended to the body of the function
        $this->env_code = <<<ENV
        \$_closure_ = ClosureMaker::getClosure($id);
        \$_ =& \$_closure_->getEnv();
ENV;

        //Create the lambda function here
        $this->lambda = create_function($this->args, $this->env_code.PHP_EOL.$this->body);
    }

    /**
     * Allows us to get the environment snapshot at the time of closure creation
     * 
     * @return <type>
     */
    public function getEnv() {
        return $this->env;
    }

    /**
     * Get the lambda function for this closure
     * 
     * @return <type>
     */
    public function getLambda() {
        return $this->lambda;
    }
}

/**
 * Main closure making class
 */
abstract class ClosureMaker {
    protected static $closure_id = 0;
    protected static $closures = array();

    /**
     * A simple parser to obtain the body and arguments of an anonymous function in
     *    the PHP 5.3 syntax
     * Ex: function (\$a, \$b) { return \$a; }
     *    will return array('args' => '$a, $b',
     *                      'body' => 'return $a;'}
     * 
     * @param <type> $code
     * @return <type>
     */
    protected static function parseCode($code) {
        $code_parts = array();

        //Parse the body of the function (find the first and last braces and get
        //   the code in-between
        $first_brace_pos = strpos($code, '{');
        $last_brace_pos = strrpos($code, '}');
        if ($first_brace_pos === false || $last_brace_pos === false) {
            throw new ClosureMakerException('Closure is malformed');
        }

        $body_start = $first_brace_pos+1;
        $body_len = max($last_brace_pos-$first_brace_pos-1, 0);
        $body = substr($code, $body_start, $body_len);
        $code_parts['body'] = $body;

        //Parse the arguments of the function (find the first paren and its matching
        //   closing paren, and get the code in-between)
        $pre_body = substr($code, 0, $first_brace_pos);
        $fist_paren_pos = strpos($pre_body, '(');
        $last_paren_pos = strrpos($pre_body, ')');
        if ($first_parent_pos === false || $last_paren_pos === false) {
            throw new ClosureMakerException('Closure is malformed');
        }
        
        $args_start = $fist_paren_pos+1;
        $args_len = max($last_paren_pos-$fist_paren_pos-1, 0);
        $args = substr($code, $args_start, $args_len);
        $code_parts['args'] = $args;

        //Return the body and the arguments
        return $code_parts;
    }

    /**
     * Get a closure we created given its ID
     * 
     * @param <type> $id
     * @return <type>
     */
    public static function getClosure($id) {
        return self::$closures[$id];
    }

    /**
     * Main entry-point. Given the closure code, and an array with the environment, return
     *   a lambda function that we can use that closes over its environment.
     * 
     * @param <type> $code
     * @param <type> $env
     * @return <type>
     */
    public static function create($code, $env=array()) {
        //Parse the closure code, and extract its body and arguments
        $code_parts = self::parseCode($code);

        //Create the closure
        $closure_id = self::$closure_id++;
        $closure = new phpClosure($closure_id,
                                  $code_parts['args'], $code_parts['body'],
                                  $env);
        self::$closures[$closure_id] = $closure;

        //Return the closure's lambda
        return $closure->getLambda();
    }
}
