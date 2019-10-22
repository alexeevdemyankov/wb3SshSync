<?php

class wb3SshSync
{
    private $localConfig = null;
    private $remoteConfig = null;
    private $syncTables = null;
    private $currentTable = null;

    private $errorLog = array();
    private $succesedTables = array();

    private $sshRemote = null;


    public function setConfig(string $xmlFile)
    {
        if (!$xmlFile) {
            $this->errorLog[] = "Нет файла конфигурации:" . $xmlFile;
            return null;
        }
        $configFile = simplexml_load_file($xmlFile);
        $this->localConfig = $configFile->local;
        $this->remoteConfig = $configFile->remote;
        $this->syncTables = $configFile->tables;
    }

    public function getResult()
    {
        return $this->succesedTables;
    }

    public function getErrors()
    {
        return $this->errorLog;
    }

    public function syncronization()
    {
        //1. Наличие конфига
        if ($this->checkConfig() === false):return null;endif;
        //2. Проверка подключений
        if ($this->checkConnect() === false):return null;endif;
        //Циклы по таблицам
        foreach ($this->syncTables->table as $syncTable) {
            $this->currentTable = $syncTable;
            //3. Создание бекапа
            if ($this->makeBackup() === false):continue;endif;
            //4. Отправка на удалекнный сервер (Отправить сравнить)
            if ($this->sendToRemote() === false):continue;endif;
            //5. Развертывание на удаленном сервере
            if ($this->makeRestore() === false):continue;endif;
            //6. Пишем в выполненые
            $this->succesedTables[] = $syncTable;
        }
    }


    //1. Наличие конфига (есть ли что в Таблицах)
    private function checkConfig()
    {
        if (sizeof($this->syncTables) > 0):return true;endif;
    }

    //2. Проверка подключений
    private function checkConnect()
    {
        //check ssh remote
        $connection = ssh2_connect($this->remoteConfig->host, 22);
        $keyAuth = ssh2_auth_pubkey_file($connection, $this->remoteConfig->sshuser, $this->remoteConfig->sshkeypub, $this->remoteConfig->sshkey, '');
        if ($connection && $keyAuth == true) {
            $this->sshRemote = $connection;
            return true;
        } else {
            $this->errorLog[] = "Ошибка авторизации на " . $this->remoteConfig->host;
            return false;
        }

    }


    //3. Создание бекапа
    private function makeBackup()
    {
        if (!is_dir($this->localConfig->tmpdir)):mkdir($this->localConfig->tmpdir);endif;
        if (!is_dir($this->localConfig->tmpdir)) {
            $this->errorLog[] = "Папка не существует  " . $this->localConfig->tmpdir;
            return false;
        }
        $file = $this->localConfig->tmpdir . '/' . $this->currentTable . '.sql';
        if (is_file($file)):unlink($file);endif;
        $shString = $this->localConfig->dumpbin;
        $shString .= ' -u' . $this->localConfig->mysqluser;
        $shString .= ' -p' . $this->localConfig->mysqlpassword;
        $shString .= ' ' . $this->localConfig->database;
        $shString .= ' ' . $this->currentTable;
        $shString .= ' > ' . $file;
        if ($this->localExec($shString) === false) {
            $this->errorLog[] = "Ошибка создания бекапа  " . $this->currentTable;
            return false;
        }
        return is_file($file);
    }

    //4. Отправка на удалекнный сервер (Взять отправить сравнить)
    private function sendToRemote()
    {
        $this->remoteMakeDir($this->remoteConfig->tmpdir); //Не ловим ошибку т.к. папка уже может быть
        $localFile = $this->localConfig->tmpdir . '/' . $this->currentTable . '.sql';
        $remoteFile = $this->remoteConfig->tmpdir . '/' . $this->currentTable . '.sql';
        $this->remoteExec('rm -rf ' . $remoteFile);//Не ловим ошибку т.к. Удаляем старый бекап
        if (ssh2_scp_send($this->sshRemote, $localFile, $remoteFile) === false) {
            $this->errorLog[] = "Ошибка отправки файла   " . $localFile;
            return false;
        }
        if (filesize($localFile) <> $this->remoteFileSize($remoteFile)) {
            $this->errorLog[] = "Ошибка размера файла   " . $localFile;
            return false;
        } else {
            return true;
        }
    }


    //5. Развертывание на удаленном сервере
    private function makeRestore()
    {
        $file = $this->remoteConfig->tmpdir . '/' . $this->currentTable . '.sql';
        $shString = $this->remoteConfig->mysqlbin;
        $shString .= ' -u' . $this->remoteConfig->mysqluser;
        $shString .= ' -p' . $this->remoteConfig->mysqlpassword;
        $shString .= ' ' . $this->remoteConfig->database;
        $shString .= ' < ' . $file;
        if ($this->remoteExec($shString) === false) {
            $this->errorLog[] = "Ошибка развертывания бекапа   " . $file;
            $this->remoteExec('rm -rf ' . $file);//Не ловим ошибку т.к. Удаляем  бекап
            return false;
        } else {
            return true;
        }
    }

    //Доп методы
    private function localExec(string $sh)
    {
        exec($sh, $output, $return_var);
        if ($return_var <> 0) {
            $this->errorLog[] = "Ошибка выполнения локальной комманды   " . $sh;
            return false;
        }
    }

    private function remoteExec(string $sh)
    {
        $stream = ssh2_exec($this->sshRemote, $sh);
        stream_set_blocking($stream, true);
        $stream_out = ssh2_fetch_stream($stream, SSH2_STREAM_STDIO);
        $stream_outerr = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
        fclose($stream);
        stream_get_contents($stream_out);
        if (stream_get_contents($stream_outerr)) {
            $this->errorLog[] = "Ошибка выполнения удаленной комманды   " . $sh;
            return false;
        } else {
            return true;
        }
    }

    private function remoteFileSize(string $file)
    {
        $sftp = ssh2_sftp($this->sshRemote);
        $fileInfo = ssh2_sftp_stat($sftp, $file);
        return $fileInfo['size'];
    }

    private function remoteMakeDir(string $directory)
    {
        $sftp = ssh2_sftp($this->sshRemote);
        ssh2_sftp_mkdir($sftp, $directory);
    }

}
