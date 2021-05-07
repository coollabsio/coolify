import mongoose from 'mongoose';
const { Schema } = mongoose;
export interface ISettings extends Document {
	applicationName: string;
	allowRegistration: string;
	sendErrors: Boolean;
}

const SettingsSchema = new Schema({
	applicationName: { type: String, required: true, default: 'coolify' },
	allowRegistration: { type: Boolean, required: true, default: false },
	sendErrors: { type: Boolean, required: true, default: true }
});

SettingsSchema.set('timestamps', true);

export default mongoose.model('settings', SettingsSchema);
