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
