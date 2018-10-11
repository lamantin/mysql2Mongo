<?php
set_time_limit(0);

if (0 == !posix_getuid()) {
    echo 'You need to use this script as root!';
  //   exit(0);
}

$config = parse_ini_file('mig.ini');

$migrator = new MYSQLTOMONGO($config);

$migrator->worker();
echo PHP_EOL.'all process are finished'.PHP_EOL;
exit(0);
class MYSQLTOMONGO {
    
    
    
    public $db = null;
    
    public $schemas = array();
    
    public $mongo_scrpits_dir = "";
    
    public $sql_scripts_dir = "/home/stvan/sqltomongo/";
    
    public $working_dir = "/home/stvan/";
    
    public function __construct($config) {        
        
        foreach ($config as $line => $prop) {
            $aprop        = strtolower($line);
            $this->$aprop = $prop;
        }
        var_dump($this->getconfig());
        die();
        $dsn = 'mysql:host=' . $this->mysql_host . ';dbname=' . $this->mysql_schema;
        $options = array(
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8',
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        );
        
        $this->db = new PDO($dsn, $this->mysql_username, $this->mysql_password, $options);
        mkdir($this->working_dir . 'sqltomongo');
        mkdir($this->working_dir . 'sqltomongo/sqlscripts');
        mkdir($this->working_dir . 'sqltomongo/mongoscripts');
        shell_exec('chmod -R 777 '.$this->working_dir . 'sqltomongo');
    }

    public funcion preconfigure($options){
        foreach ($options as $line => $prop) {
            $aprop        = strtolower($line);
            $this->$aprop = $prop;
        }
    }

    public function getconfig(){
        return get_object_vars($this);
    }
    
    public function enable_mysql_file_export() {
        
        /**
        
        The solution I used:
        in /etc/mysql/my.cnf add below [mysqld]
        
        secure-file-priv = ""
        
        */
        
        $data = shell_exec('grep "secure-file-priv"  /etc/mysql/my.cnf');
        if (strlen($data > 1)) {
            if ($data == 'secure-file-priv = ""')
                return;
            //replace or already exist;
            $file = file_get_contents('/etc/mysql/my.cnf');
            $file = preg_replace($data, '', $file);
            file_put_contents('/etc/mysql/my.cnf', $file);
        } else {
            file_put_contents('/etc/mysql/my.cnf', 'secure-file-priv = ""', FILE_APPEND | LOCK_EX);
        }
        
        shell_exec('service mysql restart');
    }
    
    public function tables() {
        
        $sql    = "SELECT * FROM information_schema.columns WHERE table_schema = '" . $this->mysql_schema . "' ORDER BY table_name,ordinal_position";
        $tables = $this->db->query($sql)->fetchAll();
        foreach ($tables as $table) {
            $this->shemas[$table['TABLE_NAME']][] = $table['COLUMN_NAME'];
        }
    }
    
    
    
    public function worker() {
        $this->tables();
        $this->generators();
        chdir($this->working_dir . 'sqltomongo/sqlscripts/');
        shell_exec('mysql<sql2csv.sql');
        shell_exec('cd '.$this->working_dir . 'sqltomongo/mongoscripts/');
	    chdir($this->working_dir . 'sqltomongo/mongoscripts/');
        $fp = popen("ls *.sh", "r");
        while ($rec = fgets($fp)) {
            $command = trim($rec);
            echo $command.PHP_EOL;
            shell_exec("chmod +x {$command}");
            shell_exec("./{$command}");
        }
    }
    public function generators() {
        $sqls = "";
        foreach ($this->shemas as $table => $fields) {
            
            $fields = "`" . implode("`,`", $fields) . "`";
            $query  = "select " . $fields . " into outfile '" . $this->working_dir . 'sqltomongo/sqlscripts/' . $table . ".csv' FIELDS TERMINATED BY ',' LINES TERMINATED BY '\\n' from {$table};";
            $sqls .= $query . PHP_EOL;
            $this->generateimportcommands($table, $fields);
            
        }
        file_put_contents($this->working_dir . 'sqltomongo/sqlscripts/sql2csv.sql', $sqls);
    }
    
    
    
    public function moveTohereCsv() {
        shell_exec("mv  /var/lib/mysql/" . $this->mysql_schema . "/*.csv {$this->working_dir}sqltomongo/sqlscripts/");
    }
    
    public function generateimportcommands($table, $fields) {
        
    $fields = str_replace('`', '', $fields);
	$file = $this->working_dir.'sqltomongo/sqlscripts/'.$table;
        $data   = "mongoimport --host $this->mongo_host --port $this->mongo_port --db '" . $this->mongo_db . "' --collection {$table} --type csv -f {$fields} --file {$file}.csv";
        
        file_put_contents($this->working_dir . 'sqltomongo/mongoscripts/' . $table . '_importer.sh', $data);
        
    }
    
    
    public function __destruct() {
        
        $this->db = null;
    }
    
}
