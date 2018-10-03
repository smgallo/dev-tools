#!/bin/bash
set -x

. $HOME/bin/mysql-dirs.env

$HOME/bin/services-replication stop

rm -rf $MYSQL_CONF_DIR/mysql-master.cnf $MYSQL_CONF_DIR/mysql-slave-default.cnf $MYSQL_CONF_DIR/mysql-slave-database.cnf $MYSQL_CONF_DIR/mysql-slave-logical-clock.cnf
rm -rf $MYSQL_DATA_DIR/mysql-master $MYSQL_DATA_DIR/mysql-slave-default $MYSQL_DATA_DIR/mysql-slave-database $MYSQL_DATA_DIR/mysql-slave-logical-clock
rm -rf $MYSQL_RUN_DIR/mysql-master $MYSQL_RUN_DIR/mysql-slave-default $MYSQL_RUN_DIR/mysql-slave-database $MYSQL_RUN_DIR/mysql-slave-logical-clock
rm -rf $MYSQL_TMP_DIR/mysql-master $MYSQL_TMP_DIR/mysql-slave-default $MYSQL_TMP_DIR/mysql-slave-database $MYSQL_TMP_DIR/mysql-slave-logical-clock
