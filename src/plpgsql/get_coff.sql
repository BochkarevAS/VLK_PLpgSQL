-- Function: history.get_coff(integer, numeric, numeric, date, integer)

-- DROP FUNCTION history.get_coff(integer, numeric, numeric, date, integer);

CREATE OR REPLACE FUNCTION history.get_coff(id_coff integer, arg1 numeric, arg2 numeric, arg_date date, arg_city integer)
  RETURNS numeric AS
$BODY$

	DECLARE
		r RECORD;
		i numeric := 0;
		val numeric := 0;
		coffs numeric[];
		procs numeric[];
	BEGIN

		FOR r IN (
			SELECT id, id_cp, coff, proc
			FROM history.coefficient c
			WHERE c.cid=arg_city AND c.id_cp=id_coff AND c.date_added=(SELECT date_added FROM history.coefficient cc WHERE cc.cid=arg_city AND id_cp=id_coff AND date_added<(arg_date::date + INTERVAL '1 month') ORDER BY date_added DESC LIMIT 1)
			ORDER BY c.id
		) LOOP
			i := i + 1;
			coffs[i] := r.coff;
			procs[i] := r.proc;
		END LOOP;

		IF (id_coff IN (1, 2, 3) AND (arg2 IS NOT NULL AND arg1 IS NOT NULL AND arg2!=0 AND arg1!=0)) THEN -- Итого для оборудования, итого для новые подключения
			CASE
				WHEN ((1 / (arg1::numeric / arg2)) * 100) < coffs[1] THEN val := round(arg2 / 100 * procs[1]);
				WHEN ((1 / (arg1::numeric / arg2)) * 100) < coffs[2] THEN val := round(arg2 / 100 * procs[2]);
				WHEN ((1 / (arg1::numeric / arg2)) * 100) < coffs[3] THEN val := round(arg2 / 100 * procs[3]);
				WHEN ((1 / (arg1::numeric / arg2)) * 100) < coffs[4] THEN val := round(arg2 / 100 * procs[4]);
				WHEN ((1 / (arg1::numeric / arg2)) * 100) < coffs[5] THEN val := round(arg2 / 100 * procs[5]);
				WHEN ((1 / (arg1::numeric / arg2)) * 100) >= coffs[6] THEN val := round(arg2 / 100 * procs[6]);
				ELSE val := 0;
			END CASE;
		ELSIF (id_coff = 4 AND (arg1 IS NOT NULL AND arg1!=0)) THEN -- Итого для КТВ
			CASE
				WHEN (arg1::numeric <= coffs[1]) THEN val := round(arg1 / 100 * procs[1]);
				WHEN (arg1::numeric < coffs[2]) THEN val := round(arg1 / 100 * procs[2]);
				WHEN (arg1::numeric >= coffs[3]) THEN val := round(arg1 / 100 * procs[3]);
				ELSE val := 0;
			END CASE;
		ELSIF (id_coff = 5 AND (arg2 IS NOT NULL AND arg1 IS NOT NULL AND arg2!=0 AND arg1!=0)) THEN -- Итого сумма с коэффициентом выполнения общего плана
			CASE
				WHEN (arg1::numeric < coffs[1]) THEN val := round(arg2 * procs[1]);
				WHEN (arg1::numeric < coffs[2]) THEN val := round(arg2 * procs[2]);
				WHEN (arg1::numeric < coffs[3]) THEN val := round(arg2 * procs[3]);
				WHEN (arg1::numeric < coffs[4]) THEN val := round(arg2 * procs[4]);
				WHEN (arg1::numeric < coffs[5]) THEN val := round(arg2 * procs[5]);
				WHEN (arg1::numeric >= coffs[6]) THEN val := round(arg2 * procs[6]);
				ELSE val := 0;
			END CASE;
		ELSIF (id_coff = 6 AND (arg2 IS NOT NULL AND arg1 IS NOT NULL AND arg2!=0 OR arg1!=0)) THEN -- Итого онлайн-заявки (без тех.возможности / недожатые)
			CASE
				WHEN (arg1::numeric <= coffs[1]) THEN val := round(arg1 * procs[1]);
				WHEN (arg1::numeric <= coffs[2]) THEN val := round(arg1 * procs[2]);
				WHEN (arg1::numeric >= coffs[3])THEN val := round(arg1 * procs[3]);
				ELSE val := 0;
			END CASE;
		END IF;

		RETURN val;
	END;

$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 100;
ALTER FUNCTION history.get_coff(integer, numeric, numeric, date, integer)
  OWNER TO web_acco;