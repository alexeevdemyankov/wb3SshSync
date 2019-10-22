# wb3SshSync
Deploy tables mysql over ssh


XML config example
    
<wb3_sync>
    <local>
        <host>localhost</host>
        <database>***</database>
        <mysqluser>***</mysqluser>
        <mysqlpassword>****</mysqlpassword>
        <mysqlbin>/usr/bin/mysql</mysqlbin>
        <dumpbin>/usr/bin/mysqldump</dumpbin>
        <tmpdir>/tmp</tmpdir>
    </local>
    <remote>
        <host>***</host>
        <database>***</database>
        <sshuser>***</sshuser>
        <sshpassword>***</sshpassword>
        <sshkey>/home/.ssh/***_rsa</sshkey>
        <sshkeypub>/home/.ssh/***_rsa.pub</sshkeypub>
        <mysqluser>***</mysqluser>
        <mysqlpassword>***</mysqlpassword>
        <mysqlbin>/usr/bin/mysql</mysqlbin>
        <tmpdir>/tmp</tmpdir>
    </remote>
    <tables>
        <table>***</table>
        <table>***</table>
    </tables>
</wb3_sync>


Using class

$sync = new wb3SshSync();
$sync->setConfig($xmlFile);
$sync->syncronization();
$errors = $sync->getErrors();

