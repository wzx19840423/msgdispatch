<?php

/**
 *
 * 用这个worker实现邮件服务
 * 每次从redis取一条数据，处理成功后写入数据库
 * 数据表根据月份分开创建
 *
 * @author walkor <walkor@workerman.net>
 */
class EmailWorker
{
    protected $mailer = null;
    protected $redis = null;
    protected $manager = null;
    static public $config = null;

    /**
     * 该worker进程开始服务的时候会触发一次
     * @return bool
     */
    public function start()
    {
        //echo getmypid()." start initRedis\n";
        $this->initRedis();
        //echo getmypid()." start initMailer\n";
        $this->initMailer();
        //echo getmypid()." start initMongo\n";
        $this->initMongo();
        $redis_key = $this->getConf('email_redis_key');
        while (true) {
            $data = $this->redis->lPop($redis_key);
            if (empty($data)) {
                usleep(300);
                continue;
            }
            $this->dealMail($data);
        }
        $this->end();
        return true;
    }

    /** 获取配置信息
     * @param $str
     * @return mixed
     */
    protected function getConf($str)
    {
        return isset(self::$config[$str]) ? self::$config[$str] : '';
    }

    /** 初始化redis
     *
     */
    protected function initRedis()
    {
        $parameters = array(
            'host'     => $this->getConf('redis_ip'),
            'port'     => $this->getConf('redis_port'),
            'database' => $this->getConf('redis_db')
        );
        $this->redis = new Predis\Client($parameters);
    }

    /** 初始化mailer
     *
     */
    protected function initMailer()
    {
        unset($this->mailer);
        $transport = Swift_SmtpTransport::newInstance('smtp.xx.com', 25)
            ->setUsername('xx@xx.com')
            ->setPassword('xx');
        $this->mailer = Swift_Mailer::newInstance($transport);
    }

    /** 初始化mongo
     *
     */
    protected function initMongo()
    {
        $host = $this->getConf('db_ip');
        $port = $this->getConf('db_port');
        $this->manager = new MongoDB\Driver\Manager("mongodb://{$host}:{$port}");    // 连接到mongodb
    }

    /** 回收资源
     *
     */
    protected function end()
    {
        $this->redis->close();
        unset($this->mailer);
        $this->manager->close();
    }

    /** 处理邮件
     * @param $mail_info
     * @return bool
     */
    protected function dealMail($mail_info)
    {
        //echo $mail_info."\n";
        $data = json_decode($mail_info, true);
        if (empty($data)) {
            return false;
        }
        list($usec, $sec) = explode(" ", microtime());
        $msec = round($usec*1000);
        $data['date'] = new \MongoDB\BSON\UTCDateTime(time()*1000+$msec+8*60*60*1000);
        $message = Swift_Message::newInstance($data['title'])
            ->setFrom(array('xx@xx.com' => 'OA系统邮件',))
            ->setTo($data['to'])
            ->addPart($data['body'], 'text/html');
        $fails = array();
        $result = $this->mailer->send($message, $fails);
        if ($result) {
            //echo getmypid()."send successful\n";
            //写入数据库
            $data['status'] = 'success';
            $bulk = new MongoDB\Driver\BulkWrite;
            $bulk->insert($data);
            $db_name = $this->getConf('db_name');
            $db_collection = $this->getConf('db_email_collection');
            $this->manager->executeBulkWrite("{$db_name}.{$db_collection}", $bulk);
            unset($bulk);
        } else {
            $this->initMailer();
            if (!isset($data['send_reply'])) {
                $data['send_reply'] = 1;
                $this->redis->rPush($this->getConf('email_redis_key'), json_encode($data));
            } else {
                if (++$data['send_reply'] < $this->getConf('send_reply')) {
                    $this->redis->rPush($this->getConf('email_redis_key'), json_encode($data));
                } else {
                    //echo getmypid()." send failed\n";
                    $data['status'] = 'fail';
                    $data['error'] = $fails;
                    $bulk = new MongoDB\Driver\BulkWrite;
                    $bulk->insert($data);
                    $db_name = $this->getConf('db_name');
                    $db_collection = $this->getConf('db_email_collection');
                    $this->manager->executeBulkWrite("{$db_name}.{$db_collection}", $bulk);
                    unset($bulk);
                }
            }
        }
        return true;
    }
}
