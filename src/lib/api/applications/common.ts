export const baseServiceConfiguration = {
	replicas: 1,
	restart_policy: {
		condition: 'any',
		max_attempts: 6
	},
	update_config: {
		parallelism: 1,
		delay: '10s',
		order: 'start-first'
	},
	rollback_config: {
		parallelism: 1,
		delay: '10s',
		order: 'start-first',
		failure_action: 'rollback'
	}
};
