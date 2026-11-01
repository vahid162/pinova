# Parent failure must keep this worker out until an explicitly verified reopen.
pinova_admission_dir="${PINOVA_ADMISSION_PROOF_ROOT}/admission"
pinova_admission_open="$pinova_admission_dir/open"
if [[ -L "$pinova_admission_dir" || ! -d "$pinova_admission_dir" || -L "$pinova_admission_open" || ! -f "$pinova_admission_open" ]] \
    || [[ "$(/usr/bin/stat -c '%u:%g:%a' -- "$pinova_admission_dir" 2>/dev/null)" != '0:0:755' ]] \
    || [[ "$(/usr/bin/stat -c '%u:%g:%a:%s' -- "$pinova_admission_open" 2>/dev/null)" != '0:0:644:22' ]] \
    || ! /usr/bin/cmp -s -- "$pinova_admission_open" <(printf '%s\n' 'pinova-deploy-open-v1'); then
    printf '[%s] deferred: deployment admission held or unknown\n' "$(date -Is)" >>"${PINOVA_ADMISSION_PROOF_ROOT}/private/cron.log"
    exit 75
fi
