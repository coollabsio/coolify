import mongoose from 'mongoose';
const { Schema } = mongoose;

const DeploymentSchema = new Schema( {
  deployId: { type: String, required: true },
  nickname: { type: String, required: true },
  repoId: { type: Number, required: true },
  organization: { type: String, required: true },
  name: { type: String, required: true },
  branch: { type: String, required: true },
  domain: { type: String, required: true },
  progress: { type: String, require: true, default: 'queued' }
},);

DeploymentSchema.set('timestamps', true);

export default mongoose.model('deployment', DeploymentSchema);
