import TextInput from '@/Components/TextInput';

function normalizeHex(value) {
    if (!value) return '#000000';
    let hex = String(value).trim();
    if (!hex.startsWith('#')) hex = `#${hex}`;
    if (/^#[0-9A-Fa-f]{3}$/.test(hex)) {
        hex = `#${hex[1]}${hex[1]}${hex[2]}${hex[2]}${hex[3]}${hex[3]}`;
    }
    if (!/^#[0-9A-Fa-f]{6}$/.test(hex)) return null;
    return hex.toUpperCase();
}

/**
 * Visual color swatch + hex text field.
 * onChange receives a #RRGGBB string (uppercase).
 */
export default function ColorPicker({
    value = '#000000',
    onChange,
    className = '',
    disabled = false,
    id,
}) {
    const display = normalizeHex(value) || '#000000';

    const commit = (raw) => {
        const next = normalizeHex(raw);
        if (next) onChange?.(next);
    };

    return (
        <div className={`flex items-center gap-2 ${className}`}>
            <label
                className={
                    'relative h-10 w-10 shrink-0 overflow-hidden rounded-md border border-line shadow-sm ' +
                    (disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer')
                }
                title="Pick color"
            >
                <span
                    className="absolute inset-0"
                    style={{ backgroundColor: display }}
                    aria-hidden
                />
                <input
                    id={id}
                    type="color"
                    disabled={disabled}
                    value={display}
                    onChange={(e) => commit(e.target.value)}
                    className="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                    aria-label="Color picker"
                />
            </label>
            <TextInput
                type="text"
                disabled={disabled}
                className="block w-full font-mono uppercase tracking-wide"
                value={value || ''}
                placeholder="#0E9F90"
                maxLength={7}
                onChange={(e) => {
                    const raw = e.target.value;
                    onChange?.(raw);
                    const next = normalizeHex(raw);
                    if (next && next !== raw) {
                        // keep typing flexible; normalize on blur
                    }
                }}
                onBlur={(e) => {
                    const next = normalizeHex(e.target.value);
                    if (next) onChange?.(next);
                }}
            />
        </div>
    );
}
