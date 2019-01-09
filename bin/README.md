# Useful Scripts

Note that management of a large number of hosts will benefit from using [ancible or puppet](https://www.devopsgroup.com/2018/01/10/puppet-vs-ansible/)

For a smaller set of hosts, try using [clush](https://clustershell.readthedocs.io/en/latest/tools/clush.html).

For example, suppose we have the following /etc/hosts file and we want to install ntp on all of the
hosts using the internal IP address:
```
10.10.0.17  dev-int
10.10.0.16  dev-db-int
87.1.3.51   www.webserver.com
```

With the following `install-ntp.sh` install script
```
yum -y install ntp
systemctl enable ntpd.service
systemctl start ntpd.service
ntpdate -u 0.centos.pool.ntp.org
```

Generate a coma-separated host list of internal IP addresses:
```
HOSTS=$(cat /etc/hosts| grep -- '-int' | sed 's/^[^\s]\{1,\}\s\{1,\}//' | tr '\n' ',' | sed 's/,$//')
```

Using clush, check that ntpd is not already installed and run the install script if needed on each
host:
```
clush -w $HOSTS 'systemctl status ntpd.service; [ 0 != $? ] && sudo bash /tmp/install-ntp.sh'
```

Similarly, creating a using on multiple remote hosts. Note that we want only hosts with a "-int" in
their name in the hosts file and must filter out alias hosts.

```
HOSTS=$(cat /etc/hosts| grep -- '-int' | cut -d' ' -f1 | cut -f1 | sort -u)
for host in $HOSTS; do
    bash bin/create-remote-user-adm-sudo.sh -u veeam -a veeam.pub -s -r $host
done
```
