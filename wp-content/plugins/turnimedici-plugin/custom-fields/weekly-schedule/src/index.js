import WeeklySchedule from './facility-calendar/weekly-schedule';
import ScheduleAdminField from './schedule-admin/schedule-admin-field';
import FacilityRankingField from './facility-ranking/facility-ranking-field';
import AftScheduleField from './aft-schedule/aft-schedule-field';
import './editor/document-panels';
import './facility-calendar/style.scss';
import './schedule-admin/style.scss';
import './facility-ranking/style.scss';
import './aft-schedule/style.scss';

window.DefineBlocks = window.DefineBlocks || {};
window.DefineBlocks.Extensions = window.DefineBlocks.Extensions || {};
window.DefineBlocks.Extensions['weekly-schedule'] = WeeklySchedule;
window.DefineBlocks.Extensions['schedule-admin'] = ScheduleAdminField;
window.DefineBlocks.Extensions['facility-ranking'] = FacilityRankingField;
window.DefineBlocks.Extensions['aft-orari'] = AftScheduleField;
