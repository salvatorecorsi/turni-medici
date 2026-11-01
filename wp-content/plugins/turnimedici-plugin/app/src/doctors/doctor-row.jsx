import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { copy, update } from '@wordpress/icons';
import { qualLabel, isRuap, isCaTemporaneo, isCaValidToday } from './constants';
import { getMagicLink, generateMagicLink, sendInvite } from './api';

const generateIcon = (
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
        <path d="M5 19 14 10" />
        <path d="M16.5 4.2l.95 2.35 2.35.95-2.35.95-.95 2.35-.95-2.35-2.35-.95 2.35-.95.95-2.35z" />
        <path d="M5.5 5.5l.5 1.3 1.3.5-1.3.5-.5 1.3-.5-1.3-1.3-.5 1.3-.5.5-1.3z" />
    </svg>
);

const inviteIcon = <span className="dashicons dashicons-email-alt" aria-hidden="true" />;

export default function DoctorRow({ doctor, aftName, onEdit, onMagicUrl, onInvited }) {
    const [linking, setLinking] = useState(false);
    const [inviting, setInviting] = useState(false);
    const [copied, setCopied] = useState(null);
    const dimmed = doctor.qualification === 'ca_temporaneo' && !isCaValidToday(doctor);

    const copyValue = (event, value, field) => {
        event.stopPropagation();
        if (!value) return;
        navigator.clipboard?.writeText(value);
        setCopied(field);
        setTimeout(() => setCopied(null), 1200);
    };

    const hasLink = Boolean(doctor.magic_url || doctor.has_magic_link);

    const copyLink = async () => {
        if (doctor.magic_url) {
            navigator.clipboard?.writeText(doctor.magic_url);
            return;
        }
        setLinking(true);
        try {
            const result = await getMagicLink(doctor.id);
            onMagicUrl(result.magic_url);
            navigator.clipboard?.writeText(result.magic_url);
        } catch (err) {
            window.alert(err.message);
        } finally {
            setLinking(false);
        }
    };

    const regenerate = async () => {
        if (hasLink && !window.confirm('Rigenerare il link? Quello attuale smetterà di funzionare.')) return;
        setLinking(true);
        try {
            const result = await generateMagicLink(doctor.id);
            onMagicUrl(result.magic_url);
        } catch (err) {
            window.alert(err.message);
        } finally {
            setLinking(false);
        }
    };

    const invite = async () => {
        if (!window.confirm('Inviare?')) return;
        setInviting(true);
        try {
            const result = await sendInvite(doctor.id);
            onInvited(result);
            window.alert('Email inviata.');
        } catch (err) {
            window.alert(err.message);
        } finally {
            setInviting(false);
        }
    };

    return (
        <tr className="tm-doctors__row" style={dimmed ? { opacity: 0.5 } : undefined} onClick={() => onEdit(doctor)}>
            <td>{doctor.id}</td>
            <td>{doctor.first_name}</td>
            <td>{doctor.last_name}</td>
            <td className="tm-doctors__copyable" title="Clicca per copiare" onClick={(e) => copyValue(e, doctor.email, 'email')}>
                {copied === 'email' ? 'Copiato!' : doctor.email}
            </td>
            <td className="tm-doctors__copyable" title="Clicca per copiare" onClick={(e) => copyValue(e, doctor.phone, 'phone')}>
                {copied === 'phone' ? 'Copiato!' : doctor.phone}
            </td>
            <td>{qualLabel(doctor.qualification)}</td>
            <td>{isCaTemporaneo(doctor.qualification) ? '—' : aftName}</td>
            <td>{isRuap(doctor.qualification) ? doctor.patients : '—'}</td>
            <td className="tm-doctors__actions" onClick={(event) => event.stopPropagation()}>
                {hasLink ? (
                    <>
                        <Button icon={copy} label="Copia link" onClick={copyLink} isBusy={linking} />
                        <Button icon={update} label="Rigenera link" onClick={regenerate} isBusy={linking} />
                    </>
                ) : (
                    <Button icon={generateIcon} label="Genera link" onClick={regenerate} isBusy={linking} />
                )}
                <Button
                    icon={inviteIcon}
                    label={doctor.invite_sent_label ? `Reinvia email di benvenuto. Ultimo invio: ${doctor.invite_sent_label}` : 'Invia email di benvenuto'}
                    className={`tm-doctors__invite${doctor.invite_sent_at ? ' is-sent' : ''}`}
                    onClick={invite}
                    isBusy={inviting}
                />
            </td>
        </tr>
    );
}
