{{-- Participant fields shared by "Record participant" and "Correct". $v = current values (or empty), $id = unique prefix. --}}
<div class="fin-grid">
    <label class="fin-field"><span>Towing unit</span>
        <select name="unit_id" class="oa-select" required>
            @foreach ($unitOptions as $uid => $uname)
                <option value="{{ $uid }}" @selected((string) ($v['unit_id'] ?? '') === (string) $uid)>{{ $uname }}</option>
            @endforeach
        </select>
    </label>
    <label class="fin-field"><span>Person</span>
        <select name="participant" class="oa-select" required>
            <option value="">Choose who actually worked</option>
            @foreach ($participants as $key => $label)
                <option value="{{ $key }}" @selected(($v['participant'] ?? '') === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="fin-field"><span>Role performed</span>
        <select name="commission_role" class="oa-select" required>
            <option value="">Choose a role</option>
            @foreach (\App\Models\Finance\JobPersonnel::ROLE_LABELS as $key => $label)
                <option value="{{ $key }}" @selected(($v['commission_role'] ?? '') === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="fin-field"><span>Borrowed from unit <small>(optional)</small></span>
        <select name="borrowed_from_unit_id" class="oa-select">
            <option value="">Not borrowed</option>
            @foreach ($allUnits as $uid => $uname)
                <option value="{{ $uid }}" @selected((string) ($v['borrowed_from_unit_id'] ?? '') === (string) $uid)>{{ $uname }}</option>
            @endforeach
        </select>
    </label>
    <label class="fin-field" style="flex-direction:row; align-items:center; gap:8px;">
        <input type="hidden" name="is_substitute" value="0">
        <input type="checkbox" name="is_substitute" value="1" style="width:auto; min-height:0;" @checked(! empty($v['is_substitute']))>
        <span>Substitute for the regular crew member</span>
    </label>
    <label class="fin-field fin-field--wide"><span>Notes <small>(optional)</small></span><input type="text" name="notes" maxlength="500" value="{{ $v['notes'] ?? '' }}"></label>
</div>
