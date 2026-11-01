import { qualLabel } from '../doctors/constants';

export default function DoctorSummary({ me }) {
    return (
        <section className="tmd-summary">
            <div className="tmd-summary__name">{me.first_name} {me.last_name}</div>
            <div className="tmd-chips">
                <span className="tmd-chip">{qualLabel(me.qualification)}</span>
                {!me.is_ca && <span className="tmd-chip">{me.patients} assistiti</span>}
                {me.is_mandatory && (
                    <span className="tmd-chip tmd-chip--warn">Monte ore: {me.weekly_hours}h/settimana</span>
                )}
            </div>
        </section>
    );
}
