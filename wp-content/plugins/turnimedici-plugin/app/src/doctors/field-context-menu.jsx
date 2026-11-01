import { useEffect, createPortal } from '@wordpress/element';

const selectedText = (input) => input.value.slice(input.selectionStart ?? 0, input.selectionEnd ?? 0);

const replaceSelection = (input, replacement) => {
    const start = input.selectionStart ?? input.value.length;
    const end = input.selectionEnd ?? input.value.length;
    const next = input.value.slice(0, start) + replacement + input.value.slice(end);
    const proto = input.tagName === 'TEXTAREA' ? window.HTMLTextAreaElement.prototype : window.HTMLInputElement.prototype;
    Object.getOwnPropertyDescriptor(proto, 'value').set.call(input, next);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    const caret = start + replacement.length;
    input.setSelectionRange(caret, caret);
    input.focus();
};

export default function FieldContextMenu({ x, y, target, field, onClose, onVerify, onCalculate }) {
    useEffect(() => {
        const close = () => onClose();
        const onKey = (event) => { if (event.key === 'Escape') onClose(); };
        window.addEventListener('click', close);
        window.addEventListener('keydown', onKey);
        window.addEventListener('scroll', close, true);
        return () => {
            window.removeEventListener('click', close);
            window.removeEventListener('keydown', onKey);
            window.removeEventListener('scroll', close, true);
        };
    }, [onClose]);

    const copy = async () => {
        const text = selectedText(target);
        if (text) await navigator.clipboard.writeText(text);
        onClose();
    };
    const cut = async () => {
        const text = selectedText(target);
        if (text) {
            await navigator.clipboard.writeText(text);
            replaceSelection(target, '');
        }
        onClose();
    };
    const paste = async () => {
        try {
            replaceSelection(target, await navigator.clipboard.readText());
        } catch {
            window.alert('Incolla non disponibile dal menu: usa Ctrl+V.');
        }
        onClose();
    };

    const isSelect = target && target.tagName === 'SELECT';

    return createPortal(
        <ul className="tm-fieldctx" style={{ left: x, top: y }} onClick={(event) => event.stopPropagation()} onContextMenu={(event) => event.preventDefault()}>
            {!isSelect && <li><button type="button" onClick={cut}>Taglia</button></li>}
            {!isSelect && <li><button type="button" onClick={copy}>Copia</button></li>}
            {!isSelect && <li><button type="button" onClick={paste}>Incolla</button></li>}
            {onCalculate && (
                <li className={isSelect ? '' : 'tm-fieldctx__sep'}><button type="button" onClick={() => { onClose(); onCalculate(); }}>Calcola</button></li>
            )}
            <li className={onCalculate || isSelect ? '' : 'tm-fieldctx__sep'}><button type="button" disabled={window.tmDoctors?.demoMode} onClick={() => { onClose(); onVerify(field); }}>Verifica con dati ESTAR</button></li>
        </ul>,
        document.body
    );
}
