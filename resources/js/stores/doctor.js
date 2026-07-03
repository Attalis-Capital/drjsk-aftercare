import { defineStore } from 'pinia';
import { useApi } from '@/composables/useApi';

export const useDoctorStore = defineStore('doctor', {
    state: () => ({
        dashboard: null,
        patients: [],
        notifications: [],
        alerts: [],
        loading: false,
        alertsLoading: false,
        error: null,
        // B3 (#1718): per-surface error state so a failed fetch renders a
        // distinct error banner + retry, never a green all-clear/empty state.
        dashboardError: null,
        patientsError: null,
        alertsError: null,
    }),

    actions: {
        async fetchDashboard() {
            this.loading = true;
            this.error = null;
            this.dashboardError = null;
            try {
                const api = useApi();
                const { data } = await api.get('/doctor/dashboard');
                this.dashboard = data.data;
            } catch (err) {
                this.dashboardError = err.response?.data?.error?.message || 'Failed to load dashboard';
                this.error = this.dashboardError;
            } finally {
                this.loading = false;
            }
        },

        async fetchPatients(search = '') {
            this.loading = true;
            this.error = null;
            this.patientsError = null;
            try {
                const api = useApi();
                const params = search ? { search } : {};
                const { data } = await api.get('/doctor/patients', { params });
                this.patients = data.data;
            } catch (err) {
                this.patientsError = err.response?.data?.error?.message || 'Failed to load patients';
                this.error = this.patientsError;
            } finally {
                this.loading = false;
            }
        },

        async fetchAlerts() {
            this.alertsLoading = true;
            this.alertsError = null;
            try {
                const api = useApi();
                const { data } = await api.get('/doctor/alerts');
                this.alerts = data.data;
            } catch (err) {
                // B3 (#1718): a failed alerts fetch must be visually distinct
                // from a genuine "no alerts" state, so record the error rather
                // than leaving alerts empty and rendering the all-clear banner.
                this.alertsError = err.response?.data?.error?.message || 'Failed to load alerts';
                this.error = this.alertsError;
            } finally {
                this.alertsLoading = false;
            }
        },

        async fetchNotifications() {
            try {
                const api = useApi();
                const { data } = await api.get('/doctor/notifications');
                this.notifications = data.data;
            } catch (err) {
                this.error = err.response?.data?.error?.message || 'Failed to load notifications';
            }
        },
    },
});
