# MySQL Replication Testing

Test various MySQL replication strategies.

- `mysql-dirs.env` Defines various directories to use.
- `sudo setup.sh` initialize abd start the master and slave databases. **Must set the master password for replication before running.**
- `sudo cleanup.sh` shutdown databases and clean up files.
- `services-replication` Start/Stop services, used by setup and cleanup.

## Performance Metric Collection

Enable metric collection and set up a view that will be used to query performance stats.

Run this on the master and it will be propagated to the slaves:
```sql
-- Execute directly on the slave to collect thread event counts for analyzing
-- parallelization.

UPDATE performance_schema.setup_consumers SET ENABLED = 'YES'
WHERE NAME LIKE 'events_transactions%';

UPDATE performance_schema.setup_instruments SET ENABLED = 'YES', TIMED = 'YES'
WHERE NAME = 'transaction';

CREATE DATABASE test;
CREATE VIEW test.mts_summary_trx
AS select performance_schema.events_transactions_summary_by_thread_by_event_name.THREAD_ID AS THREAD_ID,
performance_schema.events_transactions_summary_by_thread_by_event_name.COUNT_STAR AS COUNT_STAR
from performance_schema.events_transactions_summary_by_thread_by_event_name
where performance_schema.events_transactions_summary_by_thread_by_event_name.THREAD_ID
in (select performance_schema.replication_applier_status_by_worker.THREAD_ID
  from performance_schema.replication_applier_status_by_worker)
;
```

To check statistics on each slave:
```sql
SELECT * FROM test.mts_summary_trx;
SELECT SUM(count_star) FROM test.mts_summary_trx INTO @total;
SELECT 100*(COUNT_STAR/@total) AS PCT_USAGE FROM test.mts_summary_trx;
```

## Using sysbench with an OLTP workload

See [sysbench](https://github.com/akopytov/sysbench).

```
sysbench --mysql-socket=/var/lib/mysql-master/mysql.sock --mysql-user=root --mysql-db=db1 --threads=20 --events=500000 /usr/share/sysbench/oltp_insert.lua --mysql_storage_engine=myisam --tables=10 run
```

Yields good coverage from all threads
```
PCT_USAGE
40.6792
30.0839
18.0535
11.1834
```

Switching to DATABASE utilizes a single thread, as expected since we are running against a single
database

```
stop slave
set global slave_parallel_type=`DATABASE`;
start slave;
```

```
PCT_USAGE
0.0000
0.0000
0.0000
100.0000
```

Running 2 concurrent sysbench commands against 2 different databases yields fair results
```
sysbench --mysql-socket=/var/lib/mysql-master/mysql.sock --mysql-user=root --mysql-db=db1 --threads=20 --events=1000000 /usr/share/sysbench/oltp_insert.lua --mysql_storage_engine=myisam --tables=10 cleanup
sysbench --mysql-socket=/var/lib/mysql-master/mysql.sock --mysql-user=root --mysql-db=db2 --threads=20 --events=1000000 /usr/share/sysbench/oltp_insert.lua --mysql_storage_engine=myisam --tables=10 cleanup
sysbench --mysql-socket=/var/lib/mysql-master/mysql.sock --mysql-user=root --mysql-db=db1 --threads=20 --events=1000000 /usr/share/sysbench/oltp_insert.lua --mysql_storage_engine=myisam --tables=10 prepare
sysbench --mysql-socket=/var/lib/mysql-master/mysql.sock --mysql-user=root --mysql-db=db2 --threads=20 --events=1000000 /usr/share/sysbench/oltp_insert.lua --mysql_storage_engine=myisam --tables=10 prepare

sysbench --mysql-socket=/var/lib/mysql-master/mysql.sock --mysql-user=root --mysql-db=db1 --threads=20 --events=1000000 /usr/share/sysbench/oltp_insert.lua --mysql_storage_engine=myisam --tables=10 run &
sysbench --mysql-socket=/var/lib/mysql-master/mysql.sock --mysql-user=root --mysql-db=db2 --threads=20 --events=1000000 /usr/share/sysbench/oltp_insert.lua --mysql_storage_engine=myisam --tables=10 run &
```

```
PCT_USAGE
0.0000
0.0000
39.7556
60.2444
```

Running the same 2 concurrent sysbench commands against 2 different databases with LOGICAL_CLOCK
enabled yields similar results to a single run against 1 database.
```
PCT_USAGE
38.2800
28.7846
20.1202
12.8151
```

Loading 10k sample rows from jobfact_by_month in 1k chunks using LOAD DATA INFILE and LOGICAL_CLOCK
into 2 databases, copying it to a second table, and then inserting 2 more chunks yelds poor results
```
PCT_USAGE
95.2381
4.7619
0.0000
0.0000
```

Switching to DATABASE and re-running the 1k chunk test yelds much better results. It does take the
slave a while far longer to catch up than it does for the master to insert the data. Results are
similar for loading 25k records in a single chunk.
```
PCT_USAGE
0.0000
0.0000
46.4286
53.5714
```

