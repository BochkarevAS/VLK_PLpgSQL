-- Function: staff.get_plain_by_meneger(date, integer)

-- DROP FUNCTION staff.get_plain_by_meneger(date, integer);

CREATE OR REPLACE FUNCTION staff.get_plain_by_meneger(IN arg_date date, IN arg_city integer)
  RETURNS TABLE(id integer, name character varying, id_city integer, id_offices integer, abonpay_all double precision, fact_dev double precision, fact_subscr double precision, fact_move double precision, all_fact_dev double precision, all_fact_subscr double precision, alls_fact_subscr double precision, all_fact_move double precision, plain_dev numeric, plain_subscr numeric, plain_prod numeric, total_dev numeric, total_sub numeric, total_prod numeric, total_ctv numeric, total_online numeric, avgs integer, pri bigint, oabonpay double precision, twork bigint, coff_time numeric, kach numeric, bonuse numeric, okl numeric, total_coff numeric, total numeric) AS
$BODY$

	BEGIN
		RETURN QUERY
			WITH fcred AS (
				SELECT uid, prod_c as prod_code, MAX(creditall) price
				FROM history.fcredit
				GROUP BY uid, prod_c
			), cte_dev AS (
				SELECT x.added_by, SUM(x.fact_dev) fact_dev, x.is_paid
				FROM (
					SELECT sc.added_by, sc.is_paid,
						SUM(CASE
							WHEN model_type=1 THEN (CASE WHEN sc.price>0 THEN sc.price ELSE COALESCE(f.price, 0) END)
							WHEN model_type IN (2, 4, 5) THEN (CASE WHEN sc.price>0 THEN sc.price ELSE COALESCE(f.price, 0) END)
							WHEN model_type=3 THEN (CASE WHEN sc.price>0 THEN sc.price ELSE COALESCE(f.price, 0) END)
							WHEN model_type=8 THEN (CASE WHEN sc.price>0 THEN sc.price ELSE COALESCE(f.price, 0) END)
						END) fact_dev
					FROM account.srv_cost sc
					JOIN products_services ps ON (ps.id=sc.srvs_id)
					JOIN products_models pm ON (pm.prod_id=ps.id)
					JOIN account.device_model dm ON (dm.id=pm.model_id)
					LEFT JOIN (
						SELECT uid, prod_c prod_code, MAX(creditall) price
						FROM history.fcredit
						GROUP BY uid, prod_c
					) f ON (f.uid=sc.pub_id AND f.prod_code=ps.id)
					LEFT JOIN (
						SELECT public_id
						FROM account.subscr_moving
						GROUP BY public_id
					) mv ON (mv.public_id=sc.pub_id AND sc.types=2)

					WHERE sc.con_date::date>=arg_date AND sc.con_date::date<arg_date::date + INTERVAL '1 mon'
						AND sc.deleted=0
						AND model_type IN (1, 2, 3, 4, 5, 8)
						AND sc.srvs_id NOT IN (217, 218, 219, 220, 221, 263)
						AND mv.public_id IS NULL
					GROUP BY sc.added_by, sc.is_paid
				) x
				GROUP BY x.added_by, x.is_paid
			), cte_subscr AS (
				SELECT x.added_by_user, SUM(x.fact_subscr) fact_subscr, x.is_paid
				FROM (
					SELECT DISTINCT x.sid, x.added_by_user, x.fact_subscr, srv.is_paid
					FROM (
						SELECT s.id sid, added_by_user, SUM(abonpay) fact_subscr
						FROM account.subscribers s
						LEFT JOIN tariffs_current t ON (t.tid=s.tariff)
						WHERE montaj_date>=arg_date AND montaj_date<(arg_date::date + INTERVAL '1' MONTH) AND montaj_date IS NOT NULL AND public_uid>0 AND montaj=1 AND s.id NOT IN (SELECT uid FROM account.subscr_moving)
						GROUP BY added_by_user, s.id
					) x
					LEFT JOIN account.services srv ON (srv.uid=x.sid)
				) x
				GROUP BY x.added_by_user, x.is_paid
			), cte_subscr_all AS (
				SELECT added_by_user, SUM(abonpay) abonpay_all
                                FROM account.subscribers s
                                LEFT JOIN tariffs_current t ON (t.tid=s.tariff)
				WHERE montaj_date>=arg_date AND montaj_date<(arg_date::date + INTERVAL '1' MONTH)
					AND montaj_date IS NOT NULL
					AND public_uid>0
					AND montaj=1
					AND s.id NOT IN (SELECT uid FROM account.subscr_moving)
				GROUP BY added_by_user
			), cte_move AS (
				SELECT x.uid, SUM(x.fact_move) fact_move, x.confirmed
				FROM (
					SELECT x.uid, SUM(x.abonpay) fact_move, x.confirmed
					FROM (
						SELECT u1.id uid, MAX(w.work_date) work_dat, tcc.abonpay, (CASE WHEN sm.is_confirmed='t' THEN 1 ELSE 0 END) confirmed
						FROM account.subscr_moving sm
						JOIN public.users u ON (u.id=sm.public_id)
						LEFT JOIN account.users u1 ON (u1.id=sm.added_by)
						LEFT JOIN account.warrant w ON (w.uid=sm.uid AND w.work_date > sm.move_date AND w.did=sm.to_did AND w.flat=sm.to_flat)
						LEFT JOIN account.warrant_workers ww ON (ww.warrant_id=w.id)
						LEFT JOIN staff.employees e ON (e.id=ww.fitter_id)
						LEFT JOIN public.log_block lb ON (lb.uid=u.id AND te=0)
						LEFT JOIN public.tariffs_current tcc ON (tcc.tid=u.tariff)
						WHERE sm.move_date>=arg_date AND sm.move_date<arg_date::date + INTERVAL '1' MONTH
						GROUP BY u1.id, tcc.abonpay, sm.is_confirmed
					) x
					GROUP BY x.uid, x.confirmed

					UNION ALL

					SELECT tc.added_by uid, SUM(CASE WHEN (t.abonpay-t1.abonpay)<0 THEN 0 ELSE (t.abonpay-t1.abonpay) END) fact_move, tc.confirmed
					FROM history.tariff_change tc
					LEFT JOIN tariffs_current t ON (t.tid=tc.tid_new)
					LEFT JOIN tariffs_current t1 ON (t1.tid=tc.tid)
					WHERE tc.date_added>=arg_date AND tc.date_added<=arg_date::date + INTERVAL '1 month'
					GROUP BY tc.added_by, tc.confirmed

					UNION ALL

					SELECT user_added uid, SUM(service_price) fact_move, t.confirmed
					FROM history.tv_packs_pass t
					LEFT JOIN mw.service s ON s.service_id=t.pack_id
					LEFT JOIN users u ON u.id=t.uid
					WHERE date_added>=arg_date AND date_added<arg_date::date + INTERVAL '1' MONTH
					GROUP BY user_added, t.confirmed
				) x
				GROUP BY x.uid, x.confirmed
				ORDER BY 1
			)

			SELECT  x.id, x.name, x.id_city, x.id_offices, x.abonpay_all,
				x.fact_dev, x.fact_subscr, x.fact_move,
				x.all_fact_dev, x.all_fact_subscr, x.alls_fact_subscr, x.all_fact_move,
				x.plain_dev, x.plain_subscr, x.plain_prod,
				x.total_dev, x.total_sub, x.total_prod, x.total_ctv, x.total_online,
				x.avgs, x.pri, x.oabonpay, x.twork,
				x.coff_time, x.kach, x.bonuse,
				(x.uid_hours * x.twork) okl,
				history.get_coff(5, x.avgs, (x.total_dev + x.total_sub + x.total_prod), arg_date, arg_city) total_coff, -- Итого сумма с коэффициентом выполнения общего плана
				((history.get_coff(5, x.avgs, (x.total_dev + x.total_sub + x.total_prod), arg_date, arg_city) * x.coff_time * x.kach + x.total_online + (x.uid_hours * x.twork) + x.total_ctv)::numeric) total -- Итого
			FROM (
				SELECT u.id, u.name, pp.pri, COALESCE(o.abonpay, 0) oabonpay, ww.twork, pa.id_city, pa.id_offices,
					COALESCE(f1.fact_dev, 0) fact_dev, -- Факт оборудование (все оборудование проданное менеджером)
					COALESCE(f2.fact_subscr, 0) fact_subscr, -- Факт новые подключения (все новые подключения)
					COALESCE(f3.fact_move, 0) fact_move, -- Факт доп. продажи (перевод с тарифа на тариф, пакеты ТВ, антивирусы, переезд)

					COALESCE(allf1.fact_dev, 0) all_fact_dev, -- (Заявки не подтверждённые)
					COALESCE(allf2.fact_subscr, 0) all_fact_subscr, -- (Заявки не подтверждённые)
					COALESCE(allsf2.fact_subscr, 0) alls_fact_subscr, -- (Заявки не подтверждённые там может быть NULL так как нет услуги)
					COALESCE(allf3.fact_move, 0) all_fact_move, -- (Заявки не подтверждённые)
					COALESCE(subscr_all.abonpay_all, 0) abonpay_all, -- (Заявки на подключения из отчёт продаж)

					COALESCE(pl.plain_dev, 0) plain_dev, -- План оборудование (все оборудование проданное менеджером)
					COALESCE(pl.plain_subscr, 0) plain_subscr, -- План новые подключения (все новые подключения)
					COALESCE(pl.plain_prod, 0) plain_prod, -- План доп. продажи (перевод с тарифа на тариф, пакеты ТВ, антивирусы, переезд)

					history.get_coff(1, pl.plain_dev::numeric, f1.fact_dev::numeric, arg_date, arg_city) total_dev, -- Итого для оборудования  history.get_coff(ТИП, ПЛАН, ФАКТ)
					history.get_coff(2, pl.plain_subscr::numeric, COALESCE(subscr_all.abonpay_all, 0)::numeric, arg_date, arg_city) total_sub, -- Итого для новые подключения history.get_coff(ТИП, ПЛАН, ФАКТ)
					history.get_coff(3, pl.plain_prod::numeric, f3.fact_move::numeric, arg_date, arg_city) total_prod, -- Итого доп. продажи (перевод с тарифа на тариф, пакеты ТВ, антивирусы, переезд) history.get_coff(ТИП, ПЛАН, ФАКТ)
					history.get_coff(4, pp.pri::numeric, 0, arg_date, arg_city) total_ctv, -- Итого для КТВ
					history.get_coff(6, o.cnt_online::numeric, 0, arg_date, arg_city) total_online, -- Итого онлайн-заявки (без тех.возможности / недожатые)

					COALESCE(((COALESCE(f1.fact_dev/NULLIF(pl.plain_dev, 0), 0) + COALESCE(f2.fact_subscr/NULLIF(pl.plain_subscr, 0), 0) + COALESCE(f3.fact_move/NULLIF(pl.plain_prod, 0), 0)) / 3 * 100), 0)::int avgs,

					COALESCE(pah.uid_hours, 0) uid_hours,
					COALESCE(hs.hours, 0) hours,
					COALESCE(mp.coff_time, 0) coff_time,
					COALESCE(mp.kach, 0) kach,
					COALESCE(mp.bonuse, 0) bonuse

				FROM (
					SELECT u.id, u.name, et.id_offices
					FROM account.users u
					JOIN staff.employees e ON (e.account_uid=u.id)
					JOIN account.sub_department sd ON (sd.id=e.department)
					JOIN (
						SELECT DISTINCT et.emp_id, et.id_offices
						FROM staff.employee_table et
						JOIN account.sub_department sd ON (sd.id=et.dep_id)
						WHERE (et.t_date BETWEEN arg_date AND arg_date::date + INTERVAL '1 month') AND sd.gdep_id IN (10, 19)
					) et ON (et.emp_id=e.id)
					WHERE sd.gdep_id IN (10, 19) AND (e.dismissed>unix_timestamp(arg_date) OR e.dismissed=0) -- AND e.hire_date<=arg_date::date
				) u

				-- ПРОДАННОЕ ОБОРУДОВАНИЕ
				LEFT JOIN cte_dev f1 ON (f1.added_by=u.id AND f1.is_paid='t')
				LEFT JOIN cte_dev allf1 ON (allf1.added_by=u.id AND allf1.is_paid='f')

				-- ПОДКЛЮЧЁННЫЕ АБОНЕНТЫ
				LEFT JOIN cte_subscr f2 ON (f2.added_by_user=u.id AND f2.is_paid='t')
				LEFT JOIN cte_subscr allf2 ON (allf2.added_by_user=u.id AND allf2.is_paid='f')
				LEFT JOIN cte_subscr allsf2 ON (allsf2.added_by_user=u.id AND allsf2.is_paid IS NULL)
				LEFT JOIN cte_subscr_all subscr_all ON (subscr_all.added_by_user=u.id)

				-- Переезды, перевод на другой тариф, ТВ-пакеты
				LEFT JOIN cte_move f3 ON (f3.uid=u.id AND f3.confirmed=1)
				LEFT JOIN cte_move allf3 ON (allf3.uid=u.id AND allf3.confirmed=0)

				-- Кол-во отработаных часов из табеля (Здесь нет группировки по офисам!!!)
				LEFT JOIN (
					SELECT e.account_uid, SUM(to_char((et.time_to - et.time_from) + (et.stime_to - et.stime_from), 'HH24')::int) twork -- Кол-во отработаных часов из табеля
					FROM staff.employee_table et
					JOIN account.sub_department sd ON (sd.id=et.dep_id)
					JOIN staff.employees e ON (e.id=et.emp_id)
					WHERE (et.t_date BETWEEN arg_date AND arg_date::date + INTERVAL '1 month') AND sd.gdep_id IN (10, 19)
					GROUP BY e.account_uid
				) ww ON (ww.account_uid=u.id)

				-- ПЛАН ПО МЕНЕДЖЕРУ (оборудование, подключение, доп.продаж)
				LEFT JOIN (
					SELECT x.emp_id, x.full_name, account_uid,
						SUM(x.twork) twork,
						SUM(pah.hours) hours,
						SUM(x.plain_dev) plain_dev,
						SUM(x.plain_subscr) plain_subscr,
						SUM(x.plain_prod) plain_prod
					FROM (
						SELECT et.emp_id, u.full_name, account_uid, et.twork,
						    round(SUM(et.twork * pa.dev_day_price), 2) plain_dev,
						    round(SUM(et.twork * pa.subscr_day_price), 2) plain_subscr,
						    round(SUM(et.twork * pa.sum_day), 2) plain_prod

						FROM (
						    SELECT e.id, full_name, account_uid
						    FROM staff.employees e
						    JOIN account.sub_department sd ON (sd.id=e.department)
						    WHERE (dismiss_date>=arg_date OR dismiss_date IS NULL) AND sd.gdep_id IN (10, 19)
						    ORDER BY full_name
						) u

						JOIN (
						    SELECT et.emp_id, et.id_offices, SUM(to_char((et.time_to - et.time_from) + (et.stime_to - et.stime_from), 'HH24')::int) twork -- Кол-во отработаных часов из табеля
						    FROM staff.employee_table et
						    JOIN account.sub_department sd ON (sd.id=et.dep_id)
						    WHERE (et.t_date BETWEEN arg_date AND arg_date::date + INTERVAL '1 month') AND sd.gdep_id IN (10, 19)
						    GROUP BY et.emp_id, et.id_offices
						) et ON (et.emp_id=u.id)

						LEFT JOIN (
						    SELECT  p.subscr_day_price, p.dev_day_price, p.sum_day, p.id_offices, p.id_city
						    FROM history.plain_ao p
						    WHERE p.id_city=arg_city AND p.date=(SELECT aa.date FROM history.plain_ao aa WHERE aa.id_city=arg_city AND aa.date<(arg_date::date + INTERVAL '1 month') ORDER BY aa.date DESC LIMIT 1)
						) pa ON (pa.id_offices=et.id_offices)

						GROUP BY et.emp_id, u.full_name, account_uid, et.twork
					) x

					LEFT JOIN (
						SELECT pah.empl_id, pah.hours
						FROM history.plain_ao_hours pah
						WHERE pah.date=(SELECT aa.date FROM history.plain_ao_hours aa WHERE aa.date<(arg_date::date + INTERVAL '1 month') ORDER BY aa.date DESC LIMIT 1)
					) pah ON (pah.empl_id=x.emp_id)

					GROUP BY x.emp_id, x.full_name, account_uid
				) pl ON (pl.account_uid=u.id)

				-- КТВ
				LEFT JOIN (
					SELECT sc.added_by, COUNT(*) * 200 pri
					FROM account.srv_cost sc
					LEFT JOIN users u ON (u.id=sc.pub_id)
					WHERE sc.con_date::date>=arg_date AND sc.con_date::date<arg_date::date + INTERVAL '1 mon' AND sc.is_paid = 't' AND sc.srvs_id IN (181, 272) AND sc.deleted=0
					GROUP BY sc.added_by
				) pp ON (pp.added_by=u.id)

				-- Онлайн-заявки
				LEFT JOIN (
					SELECT (abonpay * COUNT(*)) abonpay, so.added_by, add_date, COUNT(*) cnt_online
					FROM (
						SELECT so.added_by, so.is_satisfied, so.did, to_char(so.add_date, 'YYYY-MM-DD') add_date, abonpay
						FROM account.subscr_online so
						LEFT JOIN tariffs_current t ON (t.tid=so.tariff)
						WHERE (so.add_date>=arg_date::date AND so.add_date<=arg_date::date + INTERVAL '1 month') AND (so.last_call IS NULL OR so.last_call>arg_date::date + INTERVAL '1 month') AND so.tariff IS NOT NULL
						GROUP BY so.added_by, so.is_satisfied, so.did, so.add_date, abonpay
					) so
					LEFT JOIN address.dom d ON (d.id=so.did)
					LEFT JOIN fit_dem.house_con_plan hcp ON (hcp.did=so.did)
					WHERE (hcp.connected IS false OR hcp.connected IS NULL) -- Нет технической возможности
					GROUP BY so.added_by, add_date, abonpay
				) o ON (o.added_by=u.id)

				LEFT JOIN (
					SELECT kc.uid, kc.date_added, kc.coff_time, kc.kach, kc.bonuse
					FROM history.coefficient_total kc
					WHERE kc.cid=arg_city AND kc.date_added=(SELECT aa.date_added FROM history.coefficient_total aa WHERE aa.cid=arg_city AND aa.date_added<(arg_date::date + INTERVAL '1 month') ORDER BY aa.date_added DESC LIMIT 1)
				) mp ON (mp.uid=u.id)

				LEFT JOIN (
					SELECT u.id, pah.hours uid_hours
					FROM history.plain_ao_hours pah
					JOIN staff.employees e ON (e.id=pah.empl_id)
					JOIN account.users u ON (u.id=e.account_uid)
					WHERE pah.cid=arg_city AND pah.date=(SELECT aa.date FROM history.plain_ao_hours aa WHERE aa.cid=arg_city AND aa.date<(arg_date::date + INTERVAL '1 month') ORDER BY aa.date DESC LIMIT 1)
				) pah ON (pah.id=u.id)

				LEFT JOIN (
					SELECT h.date, hours
					FROM history.normal_hours h
					WHERE h.date<(arg_date::date + INTERVAL '1 month')
					ORDER BY h.date DESC
					LIMIT 1
				) hs ON (true)

				LEFT JOIN (
					SELECT p.id_city, p.id_offices
					FROM history.plain_ao p
					WHERE p.id_city=arg_city AND p.date=(SELECT aa.date FROM history.plain_ao aa WHERE aa.date<(arg_date::date + INTERVAL '1 month') AND aa.id_city=arg_city ORDER BY aa.date DESC LIMIT 1)
				) pa ON (pa.id_offices=u.id_offices)

				ORDER BY u.name
			) x;
		END;

$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 100
  ROWS 1000;
ALTER FUNCTION staff.get_plain_by_meneger(date, integer)
  OWNER TO web_acco;