import { useState, useEffect } from '@wordpress/element';
import { qualLabel } from '../doctors/constants';
import { getLinkMessages, getBoard, getPublicSlotsLink } from './api';

const stripLeadingZero = (time) => time.replace(/^0/, '');
const freeSlotRange = (key) => key.split('-').map(stripLeadingZero).join(' - ');
const localIsoDate = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

function availableSlotsMessage(board) {
    if (!board?.schedule) return '';
    const month = board.schedule.month;
    const todayIso = localIsoDate(new Date());
    const lines = [];
    (board.days || []).forEach((day) => {
        if (!day.open || day.date < todayIso) return;
        const free = (day.slots || []).filter((slot) => !slot.taken).map((slot) => freeSlotRange(slot.key));
        if (!free.length) return;
        lines.push(`${day.weekday} ${day.day}/${month.month}, ${free.join(' e ')}`);
    });
    if (!lines.length) return '';

    const monthLabel = new Date(month.year, month.month - 1, 1).toLocaleDateString('it-IT', { month: 'long', year: 'numeric' });
    const now = new Date();
    const stamp = `${now.getHours()}:${String(now.getMinutes()).padStart(2, '0')} del ${now.getDate()} ${now.toLocaleDateString('it-IT', { month: 'long' })} ${now.getFullYear()}`;
    return `Turni liberi – ${board.structure.name} – ${monthLabel}:\n\n${lines.join('\n')}\n\n_Situazione alle ${stamp}, potrebbero esserci state modifiche._`;
}

const initials = (name) =>
    name.replace(/AFT/gi, '').trim().split(/\s+/).slice(0, 2).map((w) => w.charAt(0)).join('').toUpperCase();

const MenuIcon = () => (
    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <line x1="4" y1="7" x2="20" y2="7" />
        <line x1="4" y1="12" x2="20" y2="12" />
        <line x1="4" y1="17" x2="20" y2="17" />
    </svg>
);

const SupervisorIcon = () => (
    <svg viewBox="0 -960 960 960" width="22" height="22" fill="currentColor" aria-hidden="true">
        <path d="M609-389q-29-29-29-71t29-71q29-29 71-29t71 29q29 29 29 71t-29 71q-29 29-71 29t-71-29ZM480-160v-56q0-24 12.5-44.5T528-290q36-15 74.5-22.5T680-320q39 0 77.5 7.5T832-290q23 9 35.5 29.5T880-216v56H480ZM287-527q-47-47-47-113t47-113q47-47 113-47t113 47q47 47 47 113t-47 113q-47 47-113 47t-113-47Zm113-113ZM80-160v-112q0-34 17-62.5t47-43.5q60-30 124.5-46T400-440q35 0 70 6t70 14l-34 34-34 34q-18-5-36-6.5t-36-1.5q-58 0-113.5 14T180-306q-10 5-15 14t-5 20v32h240v80H80Zm320-80Zm56.5-343.5Q480-607 480-640t-23.5-56.5Q433-720 400-720t-56.5 23.5Q320-673 320-640t23.5 56.5Q367-560 400-560t56.5-23.5Z" />
    </svg>
);

export default function TopBar({ me, structureName, scheduleId, canManage, onEditProfile, onImpersonate }) {
    const config = window.tmDashboard || {};
    const [open, setOpen] = useState(false);
    const [phase, setPhase] = useState(0);
    const aftName = me?.aft?.name || 'Turni Medici';
    const notif = me?.turnazione?.status === 'tocca_a_te' ? me.turnazione : null;
    const menuCanManage = canManage ?? !!me?.can_manage;
    const canViewRanking = !!me && (me.is_doctor !== false || menuCanManage);
    const canEditProfile = !!me && me.is_doctor !== false;
    const notifSchedule = Number(notif?.schedule_id) || 0;

    useEffect(() => {
        if (!structureName) return undefined;
        const id = setInterval(() => setPhase((p) => (p + 1) % 2), 3500);
        return () => clearInterval(id);
    }, [structureName]);

    const copyLinkMessages = async (mode) => {
        setOpen(false);
        try {
            const data = await getLinkMessages(config.facilityId);
            const text = mode === 'next' ? data.next?.message : data.all;
            if (!text) {
                window.alert(mode === 'next' ? 'Nessun medico in attesa in questo turno.' : 'Nessun medico in coda per questo mese.');
                return;
            }
            await navigator.clipboard.writeText(text);
            window.alert(mode === 'next' ? `Messaggio per ${data.next.name} copiato.` : `Copiati i messaggi di ${data.count} medici.`);
        } catch (err) {
            window.alert(err.message || 'Copia non riuscita.');
        }
    };

    const copyAvailableSlots = async () => {
        setOpen(false);
        try {
            const board = await getBoard(config.facilityId, scheduleId || 0);
            const text = availableSlotsMessage(board);
            if (!text) {
                window.alert('Nessuno slot libero da comunicare per questo mese.');
                return;
            }
            await navigator.clipboard.writeText(text);
            window.alert('Messaggio con gli slot disponibili copiato.');
        } catch (err) {
            window.alert(err.message || 'Copia non riuscita.');
        }
    };

    const copyPublicSlotsLink = async () => {
        setOpen(false);
        try {
            const data = await getPublicSlotsLink(config.facilityId, scheduleId || 0);
            await navigator.clipboard.writeText(data.url);
            window.alert('Link del calendario pubblico copiato: chi lo apre vede solo gli slot liberi e occupati del mese, senza nomi.');
        } catch (err) {
            window.alert(err.message || 'Copia non riuscita.');
        }
    };

    return (
        <header className="tmd-topbar">
            <a className="tmd-topbar__aft" href={`${config.homeUrl || '/'}#/dashboard`}>
                {me?.aft?.logo ? (
                    <img className="tmd-topbar__logo" src={me.aft.logo} alt="" />
                ) : (
                    <span className="tmd-topbar__logo tmd-topbar__logo--ph">{me?.aft ? initials(me.aft.name) : 'TM'}</span>
                )}
                <span className="tmd-topbar__aftname">
                    <span className="tmd-topbar__full">{aftName}{structureName ? ` / ${structureName}` : ''}</span>
                    {structureName ? (
                        <span className="tmd-topbar__rotate">
                            <span className={`tmd-topbar__rot${phase === 0 ? ' is-on' : ''}`}>{aftName}</span>
                            <span className={`tmd-topbar__rot${phase === 1 ? ' is-on' : ''}`}>{structureName}</span>
                        </span>
                    ) : (
                        <span className="tmd-topbar__rotate tmd-topbar__rotate--single">{aftName}</span>
                    )}
                </span>
            </a>

            <div className="tmd-topbar__right">
                {onImpersonate && (
                    <button type="button" className="tmd-topbar__tool" title="Compila al posto di un medico" onClick={onImpersonate}>
                        <SupervisorIcon />
                    </button>
                )}
                <div className="tmd-profile">
                    <button type="button" className="tmd-profile__btn" onClick={() => setOpen((o) => !o)} aria-label={notif ? 'Menu, hai una notifica' : 'Menu'}>
                        <MenuIcon />
                    </button>
                    {notif && <span className="tmd-profile__dot" aria-hidden="true" />}
                    {open && (
                        <>
                            <div className="tmd-profile__overlay" onClick={() => setOpen(false)} />
                            <div className="tmd-profile__menu">
                                <div className="tmd-profile__head">
                                    <strong>{me?.first_name} {me?.last_name}</strong>
                                    {me && <small>{me.is_doctor === false ? (me.is_admin ? 'Amministratore' : 'Coordinatore orario') : `${qualLabel(me.qualification)}${!me.is_ca ? ` · ${me.patients} assistiti` : ''}`}</small>}
                                </div>
                                {notif && (
                                    <a className="tmd-profile__notif" href={`${config.homeUrl || '/'}${notif.facility_slug || ''}${notifSchedule ? `?schedule=${notifSchedule}` : ''}`}>
                                        <span className="tmd-profile__notif-dot" aria-hidden="true" />
                                        <span>Turni da compilare{notif.facility_name ? ` · ${notif.facility_name}` : ''}</span>
                                    </a>
                                )}
                                {canEditProfile && (
                                    <button type="button" onClick={() => { setOpen(false); if (onEditProfile) onEditProfile(); }}>Modifica dati personali</button>
                                )}
                                {config.facilityId ? (
                                    <button type="button" onClick={() => { setOpen(false); window.print(); }}>Stampa orari del mese</button>
                                ) : null}
                                {menuCanManage && config.facilityId ? (
                                    <button type="button" className="tmd-desktop-only" onClick={() => copyLinkMessages('all')}>Copia messaggi link (tutti)</button>
                                ) : null}
                                {menuCanManage && config.facilityId ? (
                                    <button type="button" onClick={() => copyLinkMessages('next')}>Copia messaggio per il prossimo</button>
                                ) : null}
                                {menuCanManage && config.facilityId ? (
                                    <button type="button" onClick={copyAvailableSlots}>Copia slot disponibili</button>
                                ) : null}
                                {menuCanManage && config.facilityId ? (
                                    <button type="button" onClick={copyPublicSlotsLink}>Copia link calendario pubblico</button>
                                ) : null}
                                {canViewRanking ? (
                                    <a href={config.facilityId ? `#/graduatoria?facility=${Number(config.facilityId)}` : `${config.homeUrl || '/'}#/graduatoria`} onClick={() => setOpen(false)}>Graduatoria completa</a>
                                ) : null}
                                {config.facilityId && me?.is_doctor !== false ? (
                                    <a
                                        href={`${config.restUrl}structures/${config.facilityId}/notula?_wpnonce=${config.nonce}${scheduleId ? `&schedule=${scheduleId}` : ''}`}
                                        onClick={() => setOpen(false)}
                                    >Scarica notula (PDF)</a>
                                ) : null}
                                <a className="tmd-profile__logout" href={config.logoutUrl || '#'}>Esci</a>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </header>
    );
}
