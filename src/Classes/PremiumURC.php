<?php

// Этот класс я писал в 2015 году.

namespace App\Classes;

class PremiumURC {

    private $db;

    private $manId;

    private $date;

    private $depId; // ID из account.sub_department

    public function __construct($db, $manId, $date, $depId) {
        $this->db = $db;
        $this->manId = $manId;
        $this->date = $date;
        $this->depId = $depId;
    }

    public function setDefaultPremiumBySogl($depTo, $depFrom) {
        $sql = "
            SELECT dep_id, form_1, to_1, price_1, form_2, to_2, price_2, form_3, to_3, price_3, pui.date, pu.name_urc, id_premium_urc
            FROM history.premium_urc_interval pui
            JOIN history.premium_urc pu ON (pu.id=pui.id_premium_urc)
            WHERE pui.date=(SELECT MAX(pui.date) FROM history.premium_urc_interval pui WHERE pui.dep_id=$depFrom)
            ORDER BY id_premium_urc";
        $res = pg_query($this->db, $sql) or die_query("Не удалось получить названия премий из истории", $sql);

        if (!pg_num_rows($res)) { // Заполнение для каждого города значением по умолчянию. Выполнится только один раз для каждого города!
            $sql = "SELECT id, added_by FROM history.premium_urc WHERE id<5 LIMIT 4";
            $res = pg_query($this->db, $sql) or die($sql);

            $str = "";

            while (list($id, $added_by) = pg_fetch_array($res)) {
                $list = [$depFrom, $id, 0, 0, 0, 0, 0, 0, 0, 0, 0, $added_by];
                $str .= "( " . implode(', ', $list) . " ),";
                unset($list);
            }

            $str = substr($str, 0, -1);

            $sql = "INSERT INTO history.premium_urc_interval(dep_id, id_premium_urc, form_1, to_1, price_1, form_2, to_2, price_2, form_3, to_3, price_3, added_by) VALUES $str";
            $res = pg_query($this->db, $sql) or die($sql);

            echo "
                <script type='text/javascript'>                                 // Из-за структуры нашего приложения редирект здесь можно сделвть только через HTML или JS.
                    window.location = location.origin + '?action=premium_urc&depTo=$depTo&depFrom=$depFrom';
                </script>";
        }

        return $res;
    }

    public function setDefaultPremiumByBuild($depTo, $depFrom) {
        $sql = "
            SELECT pu.name_urc, pui.price, pui.id_premium_urc
            FROM history.premium_urc_interval_1 pui
            JOIN history.premium_urc pu ON (pu.id=pui.id_premium_urc)
            WHERE pui.date=(SELECT MAX(pui.date) FROM history.premium_urc_interval_1 pui WHERE pui.dep_id=$depTo)
            ORDER BY id_premium_urc";
        $res = pg_query($this->db, $sql) or die($sql . "Не удалось получить список с названиями премий...");

        if (!pg_num_rows($res)) { // Заполнение для каждого города значением по умолчянию. Выполнится только один раз!
            $sql = "SELECT id, added_by FROM history.premium_urc WHERE id>4 LIMIT 6";
            $res = pg_query($this->db, $sql) or die($sql);

            $str = "";

            while (list($id, $added_by) = pg_fetch_array($res)) {
                $list = [$depTo, $id, 0, $added_by];
                $str .= "( " . implode(', ', $list) . " ),";
                unset($list);
            }

            $str = substr($str, 0, -1);

            $sql = "INSERT INTO history.premium_urc_interval_1 (dep_id, id_premium_urc, price, added_by) VALUES $str";
            $res = pg_query($this->db, $sql) or die($sql);

            echo "
                <script type='text/javascript'>                                 // Из-за структуры нашего приложения редирект здесь можно сделвть только через HTML или JS.
                    window.location = location.origin + '?action=premium_urc&depTo=$depTo&depFrom=$depFrom';
                </script>";
        }

        return $res;
    }

    public function getPremium() {
        $sql = "
            WITH t AS (                                                                                         -- Размер премии за запущенный дом
                SELECT dep.sud_dep, (COUNT(*) * pui.price) price, dep.emplId
                FROM (
                    SELECT sp.did, MIN(d.dtime) dtime, rs.emplId
                    FROM switch_map.switch_place2 sp 
                    JOIN (
                        SELECT MIN(utime) dtime, swid
                        FROM switch_map.updownsw0 
                        WHERE utime IS NOT NULL
                        GROUP BY swid
                    ) d ON (sp.id=d.swid)
                    JOIN fit.rep_sogl rs ON (rs.did=sp.did)
                    GROUP BY sp.did, rs.emplId
                ) d 
                JOIN address.dom ad ON (d.did=ad.id)
                LEFT JOIN fit.rep_sogl rs ON (rs.did=d.did)
                JOIN (
                    SELECT DISTINCT e.id emplId, e.account_uid, sd.id sud_dep
                    FROM staff.employees e
                    JOIN account.sub_department sd ON (sd.id=e.department)
                ) dep ON (dep.account_uid=rs.emplId)
                JOIN (
                    SELECT id_premium_urc, dep_id, price
                    FROM history.premium_urc_interval_1 pui
                    WHERE pui.id_premium_urc=5 AND pui.date=(SELECT MAX(date) FROM history.premium_urc_interval_1 WHERE dep_id=$this->depId) 
                ) pui ON (pui.dep_id=dep.sud_dep)
                    
                WHERE date_trunc('month', d.dtime)='$this->date' 		
                GROUP BY pui.price, dep.sud_dep, dep.emplId, dep.emplId
            ), t1 AS (                                                                                          -- Размер премии за согласованный дом
                SELECT dep.sud_dep, CASE WHEN ((t.cnt - 3) * pui.price)>0 THEN (t.cnt - 3) * 2500 ELSE 0 END price, dep.emplId
                FROM (
                    SELECT COUNT(*) cnt, rs.emplId
                    FROM fit.rep_sogl rs
                    WHERE rs.sogl=1 AND rs.work_type IN (2, 3) AND ((rs.year||'-'||rs.month||'-'||'01')::date)='$this->date'
                    GROUP BY rs.emplId
                ) t
                JOIN (
                    SELECT DISTINCT e.id emplId, e.account_uid, sd.id sud_dep
                    FROM staff.employees e
                    JOIN account.sub_department sd ON (sd.id=e.department)
                ) dep ON (dep.account_uid=t.emplId)
                JOIN (
                    SELECT id_premium_urc, dep_id, price
                    FROM history.premium_urc_interval_1 pui
                    WHERE pui.id_premium_urc=6 AND pui.date=(SELECT MAX(date) FROM history.premium_urc_interval_1 WHERE dep_id=$this->depId) 
                ) pui ON (pui.dep_id=dep.sud_dep)
            ), t2 AS (                                                                                           -- Размер премии за Новострой
                SELECT dep.sud_dep, (t.flat * pui.price) price, dep.emplId
                FROM (
                    SELECT SUM(fl.flat) flat, rs.emplId
                    FROM fit.rep_sogl rs
                    LEFT JOIN (
                        SELECT did, SUM(flat_c) flat 
                        FROM fit.house_card  
                        GROUP BY did
                    ) fl ON (fl.did=rs.did)
                    WHERE rs.sogl=1 AND rs.work_type=3 AND ((rs.year||'-'||rs.month||'-'||'01')::date)='$this->date'
                    GROUP BY rs.emplId
                ) t
                JOIN (
                    SELECT DISTINCT e.id emplId, e.account_uid, sd.id sud_dep
                    FROM staff.employees e
                    JOIN account.sub_department sd ON (sd.id=e.department)
                ) dep ON (dep.account_uid=t.emplId)
                JOIN (
                    SELECT id_premium_urc, dep_id, price
                    FROM history.premium_urc_interval_1 pui
                    WHERE pui.id_premium_urc=7 AND pui.date=(SELECT MAX(date) FROM history.premium_urc_interval_1 WHERE dep_id=$this->depId) 
                ) pui ON (pui.dep_id=dep.sud_dep)
            )

            INSERT INTO staff.bonuses(emp_id, amount, comm, added_by, b_type, paydate, is_auto) 
	        SELECT t.emplId, SUM(t.price) price, t.comm, $this->manId, 2, '$this->date'::date, true
            FROM (
                SELECT t.sud_dep, t.price, t.emplId, (SELECT p.name_urc FROM history.premium_urc p WHERE p.id=5) comm
                FROM t
                UNION ALL
                SELECT t1.sud_dep, t1.price, t1.emplId, (SELECT p.name_urc FROM history.premium_urc p WHERE p.id=6) comm
                FROM t1
                UNION ALL
                SELECT t2.sud_dep, t2.price, t2.emplId, (SELECT p.name_urc FROM history.premium_urc p WHERE p.id=7) comm
                FROM t2
            ) t
            GROUP BY t.sud_dep, t.emplId, t.comm
            HAVING SUM(t.price)>0";
        $res = pg_query($this->db, $sql) or die_query_normal($sql);
    }

    public function getPremiumQuarter() { // Подсчёт квартальной премии
        $prem_list = [3, 6, 9, 12]; // Месеца начало квартальной премии.

        $date = new DateTime($this->date);
        $current_month = intval($date->format("m"));

        if (in_array($current_month, $prem_list)) {
            $sql = "
                INSERT INTO staff.bonuses(emp_id, amount, comm, added_by, b_type, paydate, is_auto)
                SELECT x.emplId, x.price, 'Авто квартальная премия', $this->manId, 2, '$this->date'::date, true
                FROM ( 
                    SELECT dep.emplId,
                        CASE 
                            WHEN t.cnt>=12 AND 14>=t.cnt THEN (SELECT price FROM history.premium_urc_interval_1 pui WHERE pui.id_premium_urc=8 AND pui.date=(SELECT MAX(date) FROM history.premium_urc_interval_1 WHERE dep_id=$this->depId))
                            WHEN t.cnt>=15 AND 17>=t.cnt THEN (SELECT price FROM history.premium_urc_interval_1 pui WHERE pui.id_premium_urc=9 AND pui.date=(SELECT MAX(date) FROM history.premium_urc_interval_1 WHERE dep_id=$this->depId))
                            WHEN 18<=t.cnt THEN (SELECT price FROM history.premium_urc_interval_1 pui WHERE pui.id_premium_urc=10 AND pui.date=(SELECT MAX(date) FROM history.premium_urc_interval_1 WHERE dep_id=$this->depId))
                        ELSE 0
                        END price
                    FROM (
                        SELECT COUNT(*) cnt, rs.emplId
                        FROM fit.rep_sogl rs
                        WHERE rs.sogl=1 AND rs.work_type IN (2, 3) AND ((rs.year||'-'||rs.month||'-'||'01')::date) BETWEEN ('$this->date'::date - INTERVAL '2 month') AND ('$this->date'::date + INTERVAL '1 month' - INTERVAL '1 day')
                        GROUP BY rs.emplId
                    ) t
                    JOIN (
                        SELECT DISTINCT e.id emplId, e.account_uid, sd.id sud_dep
                        FROM staff.employees e
                        JOIN account.sub_department sd ON (sd.id=e.department)
                    ) dep ON (dep.account_uid=t.emplId)
                    WHERE dep.sud_dep=$this->depId
                ) x
                WHERE x.price>0";
            $res = pg_query($this->db, $sql) or die_query_normal($sql);
        }
    }

    public function getPremiumDepBuild() { // Премия для отдела строительства
        $sql = "
            WITH cte AS (
                SELECT x.report_id, x.city_id, SUM(x.total_new_houses) total_new_houses, SUM(x.total_ctv_houses) total_ctv_houses
                FROM (
                    SELECT bdt.team_id report_id, bdh.city_id, 
                        CASE WHEN NOT bdh.btype IN (2, 3) THEN COALESCE(bdh.flats_cnt, SUM(hc.flat_c)) ELSE 0 END total_new_houses,
                        CASE WHEN bdh.btype=2 THEN COALESCE(bdh.flats_cnt, SUM(hc.flat_c)) ELSE 0 END total_ctv_houses
                    FROM report.building_department_houses bdh
                    LEFT JOIN report.building_department_teams bdt ON (bdt.id=bdh.team_id)
                    LEFT JOIN fit.house_card hc ON (hc.did=bdh.did)
                    WHERE bdh.month IN ('$this->date') AND 3=(
                                                                SELECT bdhsl.state
                                                                FROM report.building_department_house_state_logs bdhsl
                                                                LEFT JOIN report.building_department_house_states bdhs ON (bdhs.id=bdhsl.state)
                                                                WHERE bdhsl.cts IS NULL AND bdhsl.did=bdh.did) 
                    GROUP BY bdh.id, bdt.team_id, bdh.city_id, btype
                ) x
                GROUP BY x.report_id, x.city_id
            ), tab AS (
                SELECT t.emplId, t.report_id, t.cnt, t.city_id, t.department_id,    
                    CASE 
                        WHEN k.total_new_houses BETWEEN p1.form_1 AND p1.to_1 THEN (k.total_new_houses * p1.price_1 / t.cnt) 
                        WHEN k.total_new_houses BETWEEN p1.form_2 AND p1.to_2 THEN (k.total_new_houses * p1.price_2 / t.cnt) 
                        WHEN k.total_new_houses BETWEEN p1.form_3 AND p1.to_3 THEN (k.total_new_houses * p1.price_3 / t.cnt) 
                    ELSE 0
                    END total_new_houses,
                    
                    CASE 
                        WHEN k.total_ctv_houses BETWEEN p2.form_1 AND p2.to_1 THEN (k.total_ctv_houses * p2.price_1 / t.cnt)
                        WHEN k.total_ctv_houses BETWEEN p2.form_2 AND p2.to_2 THEN (k.total_ctv_houses * p2.price_2 / t.cnt) 
                        WHEN k.total_ctv_houses BETWEEN p2.form_3 AND p2.to_3 THEN (k.total_ctv_houses * p2.price_3 / t.cnt)
                    ELSE 0
                    END total_ctv_houses

                FROM (
                    SELECT e.id emplId, bdt.team_id report_id, COUNT(*) OVER (PARTITION BY bdt.team_id, bdt.city_id) cnt, bdt.city_id, bdt.department_id
                    FROM report.building_department_teams bdt
                    LEFT JOIN staff.fitters_team ft ON (ft.t_id=bdt.team_id AND ft.d_id IN (SELECT id FROM account.sub_department WHERE gdep_id IN (3,4)) AND ft.status='Работает' AND department_id=ft.d_id)
                    LEFT JOIN staff.employees e ON (e.id=ft.f_id)
                    LEFT JOIN account.sub_department sd ON (sd.id=ft.d_id)
                    WHERE bdt.month IN ('$this->date')
                ) t
                LEFT JOIN cte k ON (k.city_id=t.city_id AND k.report_id=t.report_id)
                
                LEFT JOIN ( -- Размер премии за новое ДМХЗ 
                    SELECT *
                    FROM history.premium_urc_interval pui
                    WHERE id_premium_urc=1 AND pui.date=(SELECT MAX(p.date) FROM history.premium_urc_interval p WHERE p.dep_id=$this->depId LIMIT 1)
                ) p1 ON (p1.id_premium_urc=1)
		
                LEFT JOIN ( -- Размер премии за модернизированное ДМХЗ
                    SELECT *
                    FROM history.premium_urc_interval pui
                    WHERE id_premium_urc=2 AND pui.date=(SELECT MAX(p.date) FROM history.premium_urc_interval p WHERE p.dep_id=$this->depId LIMIT 1)
                ) p2 ON (p2.id_premium_urc=2)
            ), dep AS (
                SELECT DISTINCT e.id emplId, e.account_uid, sd.id sud_dep
                FROM staff.employees e
                JOIN account.sub_department sd ON (sd.id=e.department)
            )
        
            INSERT INTO staff.bonuses(emp_id, amount, comm, added_by, b_type, paydate, is_auto) 
            SELECT tab.emplId, total_new_houses, 'Авто новые ДМХЗ', $this->manId, 2, '$this->date'::date, true 
            FROM tab
            LEFT JOIN dep ON (dep.emplId=tab.emplId)
            WHERE dep.sud_dep=$this->depId AND total_new_houses!=0
            UNION
            SELECT tab.emplId, total_ctv_houses, 'Авто модернизация', $this->manId, 2, '$this->date'::date, true 
            FROM tab
            LEFT JOIN dep ON (dep.emplId=tab.emplId)
            WHERE dep.sud_dep=$this->depId AND total_ctv_houses!=0";
        $res = pg_query($this->db, $sql) or die_query_normal($sql);
    }

    public function getPremiumLeadDep($emplId) { // Подсчёт премии для руковадителя отдела

        $sql = "
            SELECT sd.id
            FROM account.sub_department sd
            JOIN staff.employees e ON (e.id=sd.lead)
            WHERE e.department=$this->depId";
        $res = pg_query($this->db, $sql) or die_query_normal($sql);

        $sql = "
            WITH buff AS (
                SELECT SUM(x.total_new_houses) total_new_houses, SUM(x.total_ctv_houses) total_ctv_houses, SUM(x.optical_meters) optical_meters, x.lead, x.department_id
                FROM ( 
                    SELECT CASE WHEN NOT bdh.btype IN (2, 3) THEN COALESCE(bdh.flats_cnt, SUM(hc.flat_c)) ELSE 0 END total_new_houses,
                        CASE WHEN bdh.btype=2 THEN COALESCE(bdh.flats_cnt, SUM(hc.flat_c)) ELSE 0 END total_ctv_houses,
                        bdh.optical_meters,
                        bdt.department_id,
                        e.id lead,
                        bdh.city_id
                    FROM report.building_department_houses bdh
                    LEFT JOIN report.building_department_teams bdt ON (bdt.id=bdh.team_id)
                    LEFT JOIN fit.house_card hc ON (hc.did=bdh.did)
                    LEFT JOIN account.sub_department sd ON (sd.id=bdt.department_id)
                    LEFT JOIN staff.employees e ON (e.account_uid=sd.lead)
                    WHERE bdh.month IN ('$this->date') AND 3=(
                                        SELECT bdhsl.state
                                        FROM report.building_department_house_state_logs bdhsl
                                        LEFT JOIN report.building_department_house_states bdhs ON (bdhs.id=bdhsl.state)
                                        WHERE bdhsl.cts IS NULL AND bdhsl.did=bdh.did) AND bdt.department_id IN (SELECT sd1.id FROM account.sub_department sd1 WHERE sd1.lead=(SELECT account_uid FROM staff.employees WHERE id=$emplId AND account_uid>0))
                    GROUP BY bdh.id, bdt.team_id, bdh.city_id, btype, bdh.optical_meters, bdt.department_id, e.id, bdh.city_id, bdt.department_id
                ) x
                GROUP BY x.lead, x.department_id			
            ), current_prem AS (
                SELECT *
                FROM history.premium_urc_interval pui
                WHERE pui.date=(SELECT MAX(p.date) FROM history.premium_urc_interval p WHERE p.dep_id=$this->depId LIMIT 1)
            )
            
            INSERT INTO staff.bonuses(emp_id, amount, comm, added_by, b_type, paydate, is_auto) 
            SELECT x.lead, (x.total_new_houses * SUM(x.premium)) premium, x.comm, $this->manId, 2, '$this->date'::date, true
            FROM (
                SELECT  b.lead, (SELECT p.name_urc FROM history.premium_urc p WHERE p.id=1) comm, b.total_new_houses,
                    CASE 
                        WHEN b.total_new_houses BETWEEN cp.form_1 AND cp.to_1 THEN cp.price_1
                        WHEN b.total_new_houses BETWEEN cp.form_2 AND cp.to_2 THEN cp.price_2
                        WHEN b.total_new_houses BETWEEN cp.form_3 AND cp.to_3 THEN cp.price_3
                    ELSE 0
                    END premium
                FROM buff b
                JOIN current_prem cp ON (cp.id_premium_urc=1)	
                UNION ALL
                SELECT  b.lead, (SELECT p.name_urc FROM history.premium_urc p WHERE p.id=2) comm, b.total_ctv_houses,
                    CASE 
                        WHEN b.total_ctv_houses BETWEEN cp.form_1 AND cp.to_1 THEN cp.price_1
                        WHEN b.total_ctv_houses BETWEEN cp.form_2 AND cp.to_2 THEN cp.price_2
                        WHEN b.total_ctv_houses BETWEEN cp.form_3 AND cp.to_3 THEN cp.price_3
                    ELSE 0
                    END premium
                FROM buff b
                JOIN current_prem cp ON (cp.id_premium_urc=2)  
                UNION ALL 
                SELECT  b.lead, (SELECT p.name_urc FROM history.premium_urc p WHERE p.id=3) comm, b.optical_meters,
                    CASE 
                        WHEN b.optical_meters BETWEEN cp.form_1 AND cp.to_1 THEN cp.price_1
                        WHEN b.optical_meters BETWEEN cp.form_2 AND cp.to_2 THEN cp.price_2
                        WHEN b.optical_meters BETWEEN cp.form_3 AND cp.to_3 THEN cp.price_3
                    ELSE 0
                    END premium
                FROM buff b
                JOIN current_prem cp ON (cp.id_premium_urc=3)
            ) x
            WHERE x.premium>0
            GROUP BY x.lead, x.comm, x.total_new_houses";
        $res = pg_query($this->db, $sql) or die_query_normal($sql);

    }

    public function getPremiumBrack($emplId) {
        $sql = "
            WITH remarks_list AS ( -- Отседа собераю http://account.vladlink.lan/index.php?action=report&type=demands_quality&city=1&dep_id[4]=on&months[2016-12]=2016-12&go=1 данные.
                SELECT 
                    CASE WHEN fr.types_id=0 THEN 0 ELSE 100 END grp_id,
                    CASE WHEN fr.types_id=0 THEN 'Общие' ELSE 'Старые' END grp_name,
                    remark_name, fr.id as remark_id, false as is_type, fr.remark_fine as fine, fr.manager
                FROM fit_dem.fit_remark fr
                LEFT JOIN fit_dem.remark_insert ri ON (ri.remark_id=fr.id AND ri.is_type=false AND ri.deleted=0)
                LEFT JOIN fit_dem.demands d ON (d.id=fit_id)
                WHERE (fr.types_id=0 OR (NOT ri.id IS NULL)) AND NOT remark_name IN ('Актуализировать расходник', 'Тест', 'тест', '') AND NOT fr.id in (249) AND manager=true
                UNION
                SELECT frt.id, frt.remark_type_name, remark_name, frtr.id as remark_id, true as is_type, frtr.remark_fine fine, frtr.manager
                FROM fit_dem.fit_remark_type_remarks frtr
                LEFT JOIN fit_dem.fit_remark_types frt ON (frt.id=frtr.type_id)
                LEFT JOIN fit_dem.remark_insert ri ON (ri.remark_id=frtr.id AND ri.is_type=true AND ri.deleted=0)
                WHERE manager=true
	        ), buff AS (
                SELECT x.dep, SUM(x.dcnt) cnt
                FROM (
                    SELECT sd.id dep, CASE WHEN demands.dcnt > 0 THEN demands.dcnt ELSE 0 END dcnt, ft.t_id
                    FROM staff.fitters_team ft
                    LEFT JOIN account.sub_department sd ON (sd.id=ft.d_id)
                    LEFT JOIN staff.employees u ON (u.id=ft.f_id)
                    LEFT JOIN (
                        SELECT w.team, COUNT(d.id) dcnt, sd.id dep
                        FROM fit_dem.demands d
                        LEFT JOIN fit_work.warrants w ON (w.id= d.warrant_id)
                        LEFT JOIN account.sub_department sd ON (sd.id=w.dep_id)
                        WHERE ((d.closedate>='$this->date' AND d.closedate<'$this->date'::date + INTERVAL '1 month'))
                        GROUP BY w.team, sd.id
                    ) demands ON (demands.team=ft.t_id AND demands.dep=sd.id)
                    WHERE ft.status='Работает'
                    GROUP BY sd.id, demands.dcnt, ft.t_id ORDER BY 1
                ) x
                GROUP BY x.dep
	        ), current_prem AS (
                SELECT *
                FROM history.premium_urc_interval pui
                WHERE pui.date=(SELECT MAX(p.date) FROM history.premium_urc_interval p WHERE p.dep_id=$this->depId LIMIT 1) AND pui.id_premium_urc=4 
	        )
	
            INSERT INTO staff.bonuses(emp_id, amount, comm, added_by, b_type, paydate, is_auto)
            SELECT t.lead, t.premium, t.comm, $this->manId, 2, '$this->date'::date, true
            FROM ( 
                SELECT  x.lead, (SELECT p.name_urc FROM history.premium_urc p WHERE p.id=4) comm,
                    CASE 
                        WHEN round((SUM(x.cnt) / b.cnt * 100), 1) BETWEEN cp.form_1 AND cp.to_1 THEN cp.price_1
                        WHEN round((SUM(x.cnt) / b.cnt * 100), 1) BETWEEN cp.form_2 AND cp.to_2 THEN cp.price_2
                        WHEN round((SUM(x.cnt) / b.cnt * 100), 1) BETWEEN cp.form_3 AND cp.to_3 THEN cp.price_3
                    ELSE 0
                    END premium
        
                FROM (
                    SELECT grp_id, grp_name, remark_name as rname, array_to_json(ARRAY_AGG(team||'+'||cnt||'+'||demands)) remarks, fine, manager, demands::text, SUM(cnt) cnt, teams.dep, teams.lead
                    FROM remarks_list remarks
                    LEFT JOIN (
                        SELECT ri.remark_id, ri.is_type, w.team||'+'||sd.id team, count(fit_id) cnt, e.id lead,
                            array_to_json(array_agg(fit_id||'%'||dt.abbr||'%'||d.dem_type||'%'||CASE WHEN NOT dates.intervals IS NULL THEN dates.intervals ELSE '0' END)) demands, sd.id dep
                        FROM fit_dem.remark_insert ri
                        LEFT JOIN fit_dem.demands d ON (d.id=fit_id)
                        LEFT JOIN fit_dem.demand_types dt ON (dt.id=d.task_type)
                        LEFT JOIN fit_work.warrants w ON (w.id=d.warrant_id)
                        LEFT JOIN account.sub_department sd ON (sd.id=w.dep_id)
                        LEFT JOIN staff.employees e ON (e.account_uid=sd.lead)
                        LEFT JOIN (
                            SELECT t_id, d_id, ARRAY_AGG(status) states
                            FROM staff.fitters_team
                            WHERE 'Работает'= status
                            GROUP BY t_id, d_id
                        ) ftrs ON w.team=ftrs.t_id AND sd.id=ftrs.d_id
                        LEFT JOIN (
                            SELECT s.dem_id, array_to_string(array_agg((s.adddate::date + INTERVAL '1 day')||'^'||
                                (SELECT dc.adddate::date FROM fit_dem.dem_comments dc WHERE dc.comm_type=17 AND dc.dem_id=s.dem_id AND dc.adddate>s.adddate LIMIT 1)), '|') intervals
                        FROM (
                            SELECT dc.dem_id, dc.adddate
                            FROM fit_dem.dem_comments dc
                            WHERE dc.comm_type=16
                        ) s
                        GROUP BY dem_id
                          ) dates ON (dates.dem_id=fit_id)
                          WHERE ri.deleted=0 AND ((d.closedate>='$this->date' AND d.closedate<'$this->date'::date + INTERVAL '1 month')) 
                           -- AND sd.id=$this->depId
                           
                           AND sd.id IN (SELECT sd1.id FROM account.sub_department sd1 WHERE sd1.lead=(SELECT account_uid FROM staff.employees WHERE id=$emplId AND account_uid>0))
                           
                          GROUP BY w.team, sd.id, ri.remark_id, ri.is_type, sd.id, e.id
                    ) teams ON (teams.remark_id=remarks.remark_id AND teams.is_type=remarks.is_type)
                    
                    WHERE (NOT grp_id=100 OR (grp_id=100 AND cnt>0)) AND demands::text IS NOT NULL
                    GROUP BY grp_id, grp_name, remark_name, fine, manager, demands::text, teams.dep, teams.lead
                ) x
                LEFT JOIN buff b ON (b.dep=x.dep)
                LEFT JOIN current_prem cp ON (cp.id_premium_urc=4)
                GROUP BY x.dep, b.cnt, cp.form_1, cp.form_2, cp.form_3, cp.to_1, cp.to_2, cp.to_3, cp.price_1, cp.price_2, cp.price_3, x.lead
            ) t
            WHERE t.premium>0";
        $res = pg_query($this->db, $sql) or die_query_normal($sql);
    }
}