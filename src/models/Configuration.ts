import mongoose from 'mongoose';
const { Schema } = mongoose;
const ConfigurationSchema = new Schema({
	github: {
		installation: {
			id: { type: Number, required: true }
		},
		app: {
			id: { type: Number, required: true }
		}
	},
	repository: {
		id: { type: Number, required: true },
		organization: { type: String, required: true },
		name: { type: String, required: true },
		branch: { type: String, required: true },
	},
	general: {
		deployId: { type: String, required: true },
		nickname: { type: String, required: true },
		workdir: { type: String, required: true },
	},
	build: {
		pack: { type: String, required: true },
		directory: { type: String },
		command: {
			build: { type: String },
			installation: { type: String },
			start: { type: String },
			python: {
				module: { type: String },
				instance: { type: String },
			}

		},
		container: {
			name: { type: String, required: true },
			tag: { type: String, required: true },
			baseSHA: { type: String, required: true },
		},
	},
	publish: {
		directory: { type: String },
		domain: { type: String, required: true },
		path: { type: String },
		port: { type: Number },
		secrets: { type: Array },
	}
});

ConfigurationSchema.set('timestamps', true);

export default mongoose.models['configuration'] || mongoose.model('configuration', ConfigurationSchema);
