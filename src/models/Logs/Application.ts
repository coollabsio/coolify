import mongoose from 'mongoose';
const { Schema } = mongoose;

const ApplicationLogsSchema = new Schema({
	deployId: { type: String, required: true },
	event: { type: String, required: true }
});

ApplicationLogsSchema.set('timestamps', true);

export default mongoose.model('logs-application', ApplicationLogsSchema);
