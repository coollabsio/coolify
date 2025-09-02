<template>
  <div class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3>Upload Files</h3>
        <button @click="$emit('close')" class="close-btn">×</button>
      </div>
      <div class="modal-body">
        <div class="upload-area" @drop="handleDrop" @dragover.prevent @dragenter.prevent>
          <div v-if="!uploading" class="upload-zone">
            <i class="fas fa-cloud-upload-alt"></i>
            <p>Drag and drop files here or <button @click="$refs.fileInput.click()" class="upload-btn">browse</button></p>
            <input 
              ref="fileInput" 
              type="file" 
              multiple 
              @change="handleFileSelect" 
              style="display: none"
            >
          </div>
          
          <div v-if="uploading" class="upload-progress">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Uploading {{ currentFile }}...</p>
            <div class="progress-bar">
              <div class="progress-fill" :style="{ width: progress + '%' }"></div>
            </div>
          </div>
        </div>

        <div v-if="selectedFiles.length > 0 && !uploading" class="file-list">
          <h4>Selected Files</h4>
          <div v-for="(file, index) in selectedFiles" :key="index" class="file-item">
            <span class="file-name">{{ file.name }}</span>
            <span class="file-size">{{ formatFileSize(file.size) }}</span>
            <button @click="removeFile(index)" class="remove-btn">×</button>
          </div>
        </div>

        <div v-if="!uploading" class="upload-options">
          <div class="form-group">
            <label>File Permissions:</label>
            <input v-model="permissions" type="text" pattern="[0-7]{3,4}" class="form-control" placeholder="644">
          </div>
        </div>

        <div class="form-actions">
          <button 
            v-if="selectedFiles.length > 0 && !uploading" 
            @click="uploadFiles" 
            class="btn btn-primary"
          >
            Upload {{ selectedFiles.length }} file(s)
          </button>
          <button @click="$emit('close')" class="btn btn-secondary">Cancel</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'FileUpload',
  props: {
    containerId: {
      type: String,
      required: true
    },
    currentPath: {
      type: String,
      required: true
    }
  },
  emits: ['close', 'uploaded'],
  data() {
    return {
      selectedFiles: [],
      uploading: false,
      progress: 0,
      currentFile: '',
      permissions: '644'
    };
  },
  methods: {
    handleDrop(event) {
      event.preventDefault();
      const files = Array.from(event.dataTransfer.files);
      this.addFiles(files);
    },

    handleFileSelect(event) {
      const files = Array.from(event.target.files);
      this.addFiles(files);
    },

    addFiles(files) {
      this.selectedFiles.push(...files);
    },

    removeFile(index) {
      this.selectedFiles.splice(index, 1);
    },

    async uploadFiles() {
      if (this.selectedFiles.length === 0) return;

      this.uploading = true;
      this.progress = 0;

      try {
        for (let i = 0; i < this.selectedFiles.length; i++) {
          const file = this.selectedFiles[i];
          this.currentFile = file.name;
          
          await this.uploadSingleFile(file);
          
          this.progress = ((i + 1) / this.selectedFiles.length) * 100;
        }

        this.$emit('uploaded');
        this.$emit('close');
      } catch (error) {
        this.showError('Upload failed: ' + error.message);
      } finally {
        this.uploading = false;
        this.progress = 0;
        this.selectedFiles = [];
      }
    },

    async uploadSingleFile(file) {
      const formData = new FormData();
      formData.append('file', file);
      formData.append('path', this.currentPath);
      formData.append('permissions', this.permissions);

      const response = await fetch(`/api/containers/${this.containerId}/files`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${this.getToken()}`
        },
        body: formData
      });

      const data = await response.json();
      if (!data.success) {
        throw new Error(data.message);
      }
    },

    formatFileSize(bytes) {
      if (bytes === 0) return '0 B';
      const k = 1024;
      const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    },

    getToken() {
      return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    },

    showError(message) {
      // Implement your error notification system
      alert(message);
    }
  }
};
</script>

<style scoped>
.modal {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal-content {
  background: #fff;
  border-radius: 8px;
  width: 90%;
  max-width: 600px;
  max-height: 90vh;
  overflow-y: auto;
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem;
  border-bottom: 1px solid #dee2e6;
}

.modal-body {
  padding: 1rem;
}

.close-btn {
  background: none;
  border: none;
  font-size: 1.5rem;
  cursor: pointer;
  color: #6c757d;
}

.upload-area {
  border: 2px dashed #dee2e6;
  border-radius: 8px;
  padding: 2rem;
  text-align: center;
  margin-bottom: 1rem;
  transition: border-color 0.2s;
}

.upload-area:hover {
  border-color: #007bff;
}

.upload-zone i {
  font-size: 3rem;
  color: #6c757d;
  margin-bottom: 1rem;
}

.upload-btn {
  background: none;
  border: none;
  color: #007bff;
  cursor: pointer;
  text-decoration: underline;
}

.upload-progress {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
}

.progress-bar {
  width: 100%;
  height: 20px;
  background: #e9ecef;
  border-radius: 10px;
  overflow: hidden;
}

.progress-fill {
  height: 100%;
  background: #007bff;
  transition: width 0.3s;
}

.file-list {
  margin-bottom: 1rem;
}

.file-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.5rem;
  border: 1px solid #dee2e6;
  border-radius: 4px;
  margin-bottom: 0.5rem;
}

.file-name {
  flex: 1;
  margin-right: 1rem;
}

.file-size {
  color: #6c757d;
  font-size: 0.875rem;
  margin-right: 1rem;
}

.remove-btn {
  background: #dc3545;
  color: #fff;
  border: none;
  border-radius: 50%;
  width: 24px;
  height: 24px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}

.upload-options {
  margin-bottom: 1rem;
}

.form-group {
  margin-bottom: 1rem;
}

.form-group label {
  display: block;
  margin-bottom: 0.5rem;
  font-weight: 500;
}

.form-control {
  width: 100%;
  padding: 0.5rem;
  border: 1px solid #dee2e6;
  border-radius: 4px;
  font-size: 1rem;
}

.form-actions {
  display: flex;
  gap: 0.5rem;
  justify-content: flex-end;
}

.btn {
  padding: 0.5rem 1rem;
  border: 1px solid transparent;
  border-radius: 4px;
  cursor: pointer;
  font-size: 0.875rem;
  transition: all 0.2s;
}

.btn-primary {
  background: #007bff;
  color: #fff;
  border-color: #007bff;
}

.btn-primary:hover {
  background: #0056b3;
  border-color: #0056b3;
}

.btn-secondary {
  background: #6c757d;
  color: #fff;
  border-color: #6c757d;
}
/* .btn-secondary {
  background: #6c757d;
  color: #fff;
  border-color: #6c757d;
} */

.btn-secondary:hover {
  background: #545b62;
  border-color: #545b62;
}
</style>