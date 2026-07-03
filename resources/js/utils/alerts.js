// Mission #1718 S17: single shared alert-label helper with an explicit hr_drop
// case, so DoctorDashboard and DoctorPatientDetail never disagree (hr_drop was
// previously mislabelled as "BP Trend").
//
// #1718 B1 clinical-loop alert types (urgent_triage, critical_chat) are also
// labelled here so the pinned clinical alerts render meaningful badges.
export function alertLabel(type) {
    switch (type) {
        case 'urgent_triage':
            return 'Urgent Triage';
        case 'critical_chat':
            return 'Critical Chat';
        case 'weight_gain':
            return 'Weight Alert';
        case 'hr_drop':
            return 'Heart Rate';
        case 'elevated_bp':
            return 'BP Trend';
        default:
            return 'Alert';
    }
}
