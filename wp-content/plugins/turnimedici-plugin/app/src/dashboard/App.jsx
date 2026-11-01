import { useState, useEffect } from '@wordpress/element';
import { getMe } from './api';
import TopBar from './topbar';
import Dashboard from './Dashboard';
import StructureView from './structure-view';
import ProfileModal from './profile-modal';
import RankingView from './ranking-view';
import DoctorsView from './doctors-view';

const hashRoute = () => {
    const raw = window.location.hash.replace(/^#\/?/, '');
    const [view, query = ''] = raw.split('?');
    return { view, params: new URLSearchParams(query) };
};

const profileParamKeys = ['tm_profile', 'tm_edit_profile', 'profile'];

const urlParamEnabled = (params, key) => {
    if (!params.has(key)) return false;
    return !['0', 'false', 'no'].includes(String(params.get(key)).toLowerCase());
};

const shouldOpenProfileModal = (routeParams) => {
    const searchParams = new URLSearchParams(window.location.search);
    return profileParamKeys.some((key) => (
        urlParamEnabled(searchParams, key) || urlParamEnabled(routeParams, key)
    ));
};

const clearProfileModalParams = () => {
    const url = new URL(window.location.href);
    profileParamKeys.forEach((key) => url.searchParams.delete(key));

    if (url.hash.includes('?')) {
        const [hashPath, hashQuery = ''] = url.hash.split('?');
        const params = new URLSearchParams(hashQuery);
        profileParamKeys.forEach((key) => params.delete(key));
        const query = params.toString();
        url.hash = query ? `${hashPath}?${query}` : hashPath;
    }

    window.history.replaceState(window.history.state, '', url.toString());
};

export default function App() {
    const [me, setMe] = useState(null);
    const [error, setError] = useState(null);
    const [structureName, setStructureName] = useState('');
    const [structureCanManage, setStructureCanManage] = useState(false);
    const [structureScheduleId, setStructureScheduleId] = useState(0);
    const [profileOpen, setProfileOpen] = useState(false);
    const [profileRequired, setProfileRequired] = useState(false);
    const [impersonateOpen, setImpersonateOpen] = useState(false);
    const [route, setRoute] = useState(hashRoute());
    const facilityId = Number((window.tmDashboard || {}).facilityId) || 0;

    const confirmationRequired = (payload) => (
        payload?.is_doctor !== false
        && payload?.profile_confirmed === false
        && payload?.require_profile_confirmation !== false
    );

    useEffect(() => {
        getMe().then((payload) => {
            setMe(payload);
            if (confirmationRequired(payload)) {
                setProfileRequired(true);
                setProfileOpen(true);
            }
        }).catch((err) => setError(err.message));
    }, []);

    useEffect(() => {
        const onHashChange = () => setRoute(hashRoute());
        window.addEventListener('hashchange', onHashChange);
        return () => window.removeEventListener('hashchange', onHashChange);
    }, []);

    useEffect(() => {
        if (shouldOpenProfileModal(route.params)) {
            setProfileRequired(confirmationRequired(me));
            setProfileOpen(true);
            clearProfileModalParams();
        }
    }, [route, me]);

    useEffect(() => {
        if (!me || facilityId || route.view || profileOpen || confirmationRequired(me)) return;
        const preferred = me.preferred_facility;
        if (!preferred?.slug) return;
        const home = (window.tmDashboard || {}).homeUrl || '/';
        window.location.replace(`${home}${preferred.slug}`);
    }, [me, facilityId, route.view, profileOpen]);

    useEffect(() => {
        if (!facilityId) setStructureCanManage(false);
    }, [facilityId]);

    const rankingFacilityId = Number(route.params.get('facility')) || (route.view === 'graduatoria' ? facilityId : 0);
    const canViewRanking = !!me && (me.is_doctor !== false || me.is_admin || (!facilityId && me.can_manage) || (facilityId && structureCanManage));
    const closeProfile = () => {
        if (!profileRequired) setProfileOpen(false);
    };
    const saveProfileState = ({ confirmed } = {}) => {
        if (confirmed) {
            setProfileRequired(false);
            setProfileOpen(false);
        }
        getMe().then((payload) => {
            setMe(payload);
            if (confirmed || payload?.profile_confirmed !== false) {
                setProfileRequired(false);
            }
        }).catch((err) => setError(err.message));
    };

    const renderMain = () => {
        if (facilityId) {
            if (route.view === 'graduatoria' && canViewRanking) {
                return <RankingView me={me} facilityId={rankingFacilityId} />;
            }
            return <StructureView facilityId={facilityId} onTitle={setStructureName} onManagementScope={setStructureCanManage} onScheduleChange={setStructureScheduleId} impersonateOpen={impersonateOpen} onImpersonateClose={() => setImpersonateOpen(false)} />;
        }
        if (canViewRanking && route.view === 'graduatoria') {
            return <RankingView me={me} facilityId={rankingFacilityId} />;
        }
        if (me?.can_manage && route.view === 'medici') {
            return <DoctorsView me={me} />;
        }
        return <Dashboard me={me} />;
    };

    return (
        <div className="tmd-app">
            <TopBar me={me} structureName={facilityId ? structureName : ''} scheduleId={facilityId ? structureScheduleId : 0} canManage={facilityId ? structureCanManage : !!me?.can_manage} onEditProfile={() => { setProfileRequired(confirmationRequired(me)); setProfileOpen(true); }} onImpersonate={facilityId && structureCanManage ? () => setImpersonateOpen(true) : null} />
            {error ? (
                <div className="tmd-error">{error}</div>
            ) : !me ? (
                <div className="tmd-loading">Caricamento…</div>
            ) : (
                renderMain()
            )}
            {profileOpen && <ProfileModal forceConfirm={profileRequired} onClose={closeProfile} onSaved={saveProfileState} />}
        </div>
    );
}
