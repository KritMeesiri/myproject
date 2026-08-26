#!/usr/bin/env bash
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PYTHON="$SCRIPT_DIR/python/.venv/bin/python3"
PY_DIR="$SCRIPT_DIR/python/.venv"
CPP_DIR="$SCRIPT_DIR/sgxsdk/SampleCode/SampleEnclave"
source $SCRIPT_DIR/sgxsdk/environment

n=64
b=5
m=3

usage() {
    cat <<EOF
Usage: $0 [-n BYTES] [-b BATCH] [-m REPS]

  -n BYTES   Message size in bytes passed to each Python file (default: $n)
  -b BATCH   Batch size: Python files run b times per repetition; passed as
             second arg to the C++ app (default: $b)
  -m REPS    Number of repetitions (default: $m)
  -h         Show this help

Example:
  $0 -n 128 -b 10 -m 5
EOF
}

is_pos_int() { [[ "$1" =~ ^[1-9][0-9]*$ ]]; }

while getopts ":n:b:m:h" opt; do
    case $opt in
        n) n="$OPTARG" ;;
        b) b="$OPTARG" ;;
        m) m="$OPTARG" ;;
        h) usage; exit 0 ;;
        :) echo "Error: -$OPTARG requires an argument" >&2; usage >&2; exit 1 ;;
        \?) echo "Error: unknown option -$OPTARG" >&2; usage >&2; exit 1 ;;
    esac
done

for flag_name in n b m; do
    val="${!flag_name}"
    if ! is_pos_int "$val"; then
        echo "Error: -$flag_name must be a positive integer (got '$val')" >&2
        exit 1
    fi
done

CSV_FILE="benchmark_results.csv"
echo "rep,record,scheme,time_ms" > "$CSV_FILE"

echo "Benchmark: n=${n} bytes  b=${b} records/batch  m=${m} repetitions"
echo "CSV output: ${CSV_FILE}"
echo "========================================================================"

for rep in $(seq 1 "$m"); do
    echo ""
    echo "=== Repetition ${rep}/${m}  (n=${n} bytes, b=${b} records, m=${m}) ==="

    for record in $(seq 1 "$b"); do
        echo "  -- Record ${record}/${b} --"

        t=$("$PYTHON" "$PY_DIR/ntru_iblrscs.py" "$n" 2>/dev/null)
        printf "    [ntru ]  %s ms\n" "$t"
        echo "${rep},${record},ntru,${t}" >> "$CSV_FILE"

        t=$("$PYTHON" "$PY_DIR/pq_clscl.py" "$n" 2>/dev/null)
        t=$(awk "BEGIN {printf \"%.5f\", ${t} * 1000}")
        printf "    [pqcl ]  %s ms\n" "$t"
        echo "${rep},${record},pqcl,${t}" >> "$CSV_FILE"

        t=$("$PYTHON" "$PY_DIR/vanet_ring_signcryption.py" "$n" 2>/dev/null)
        printf "    [vanet]  %s ms\n" "$t"
        echo "${rep},${record},vanet,${t}" >> "$CSV_FILE"
    done
    t=$(cd "$CPP_DIR" && ./app "$n" "$b" 2>/dev/null </dev/null)
    printf "  [cpp  ]  %s ms  (n=%s  b=%s)\n" "$t" "$n" "$b"
    echo "${rep},-,cpp,${t}" >> "$CSV_FILE"
done

echo ""
echo "========================================================================"
echo "Done. Results saved to ${CSV_FILE}"
