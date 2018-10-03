#!/bin/bash
#
# Setup tailored to Ubuntu 16.04 and MySQL 5.7
#
# Set up a master and 3 slave instances on the same host (4 cores and 4GB of memory are sufficient
# for a simple test) to test different replication strategies and parameters.

. $HOME/bin/mysql-dirs.env

# Disable the mysql apparmor profile (only needs to happen once)
ln -s /etc/apparmor.d/usr.sbin.mysqld /etc/apparmor.d/disable/
if [ 0 -eq $? ]; then
    apparmor_parser -R /etc/apparmor.d/usr.sbin.mysqld
fi

# Create various directories
install -o mysql -g mysql -m 0644 $ASSETS_DIR/mysql-master.cnf $MYSQL_CONF_DIR/mysql-master.cnf
install -o mysql -g mysql -m 0644 $ASSETS_DIR/mysql-slave-default.cnf $MYSQL_CONF_DIR/mysql-slave-default.cnf
install -o mysql -g mysql -m 0644 $ASSETS_DIR/mysql-slave-database.cnf $MYSQL_CONF_DIR/mysql-slave-database.cnf
install -o mysql -g mysql -m 0644 $ASSETS_DIR/mysql-slave-logical-clock.cnf $MYSQL_CONF_DIR/mysql-slave-logical-clock.cnf
install -o mysql -g mysql -m 0775 -d $MYSQL_DATA_DIR/mysql-master
install -o mysql -g mysql -m 0775 -d $MYSQL_DATA_DIR/mysql-slave-default $MYSQL_DATA_DIR/mysql-slave-database $MYSQL_DATA_DIR/mysql-slave-logical-clock
install -o mysql -g mysql -m 0775 -d $MYSQL_RUN_DIR/mysql-master
install -o mysql -g mysql -m 0775 -d $MYSQL_RUN_DIR/mysql-slave-default $MYSQL_RUN_DIR/mysql-slave-database $MYSQL_RUN_DIR/mysql-slave-logical-clock
install -o mysql -g mysql -m 0775 -d $MYSQL_TMP_DIR/mysql-master
install -o mysql -g mysql -m 0775 -d $MYSQL_TMP_DIR/mysql-slave-default $MYSQL_TMP_DIR/mysql-slave-database $MYSQL_TMP_DIR/mysql-slave-logical-clock

# Initialize each mysql instance with the default database and root with empty password
sudo -u mysql mysqld --defaults-file=$MYSQL_CONF_DIR/mysql-master.cnf --initialize-insecure
sudo -u mysql mysqld --defaults-file=$MYSQL_CONF_DIR/mysql-slave-default.cnf --initialize-insecure
sudo -u mysql mysqld --defaults-file=$MYSQL_CONF_DIR/mysql-slave-database.cnf --initialize-insecure
sudo -u mysql mysqld --defaults-file=$MYSQL_CONF_DIR/mysql-slave-logical-clock.cnf --initialize-insecure

$HOME/bin/services-replication start

# Use localhost if bind_address=127.0.0.1 (uses local socket), otherwise use 127.0.0.1
# MASTER=localhost
MASTER=127.0.0.1
MASTER_PASSWD=C4jt7ClvKFv9

if [[ -z $MASTER_PASSWD ]]; then
    echo "Must set master password for replication"
    exit 1
fi

# Setup the replication
mysql -S $MYSQL_DATA_DIR/mysql-master/mysql.sock -e "GRANT REPLICATION SLAVE ON *.* TO 'slavedb'@'127.0.0.1' IDENTIFIED BY '$MASTER_PASSWD';"
mysql -S $MYSQL_DATA_DIR/mysql-slave-default/mysql.sock -e "CHANGE MASTER TO MASTER_HOST='$MASTER', MASTER_USER='slavedb', MASTER_PASSWORD='$MASTER_PASSWD', MASTER_PORT=3306; START SLAVE;"
mysql -S $MYSQL_DATA_DIR/mysql-slave-database/mysql.sock -e "CHANGE MASTER TO MASTER_HOST='$MASTER', MASTER_USER='slavedb', MASTER_PASSWORD='$MASTER_PASSWD', MASTER_PORT=3306; START SLAVE;"
mysql -S $MYSQL_DATA_DIR/mysql-slave-logical-clock/mysql.sock -e "CHANGE MASTER TO MASTER_HOST='$MASTER', MASTER_USER='slavedb', MASTER_PASSWORD='$MASTER_PASSWD', MASTER_PORT=3306; START SLAVE;"

# Initialize performance statistics and a view for checking stats
mysql -S $MYSQL_DATA_DIR/mysql-slave-default/mysql.sock < $ASSETS_DIR/mysql-slave-setup.sql
mysql -S $MYSQL_DATA_DIR/mysql-slave-database/mysql.sock < $ASSETS_DIR/mysql-slave-setup.sql
mysql -S $MYSQL_DATA_DIR/mysql-slave-logical-clock/mysql.sock < $ASSETS_DIR/mysql-slave-setup.sql

