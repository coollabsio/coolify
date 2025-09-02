<template>
  <div class="file-browser">
    <!-- Header with navigation -->
    <div class="browser-header">
      <div class="breadcrumb">
        <button 
          v-for="(segment, index) in pathSegments" 
          :key="index"
          @click="navigateToPath(getPathUpTo(index))"
          class="breadcrumb-item"
        >
          {{ segment || 'Root' }}
        </button>
      </div>
      
      <div class="header-actions">
        <button @click="showUpload = true" class="btn btn-primary">
          <i class="fas fa-upload"></i> Upload
        </button>
        <button @click="showCreateFolder = true" class="btn btn-secondary">
          <i class="fas fa-folder-plus"></i> New Folder
        </button>
        <button @click="refreshFiles" class="btn btn-outline">
          <i class="fas fa-refresh"></i> Refresh
        </button>
      </div>
    </div>

    <!-- Quick access to mounted volumes -->
    <div v-if="mounts.length > 0" class="mounted-volumes">
      <h4>Mounted Volumes</h4>
      <div class="volume-list">
        <button 
          v-for="mount in mounts" 
          :key="mount.destination"
          @click="navigateToPath(mount.destination)"
          class="volume-item"
        >
          <i class="fas fa-folder-open"></i>
          {{ mount.destination }}
          <span class="volume-type">{{ mount.type }}</span>
        </button>
      </div>
    </div>

    <!-- File listing -->
    <div class="file-list">
      <div v-if="loading" class="loading">
        <i class="fas fa-spinner fa-spin"></i> Loading files...
      </div>
      
      <div v-else-if="files.length === 0" class="empty-state">
        <i class="fas fa-folder-open"></i>
        <p>This directory is empty</p>
      </div>
      
      <div v-else class="file-grid">
        <div 
          v-for="file in files" 
          :key="file.name"
          @click="handleFileClick(file)"
          @contextmenu.prevent="showContextMenu($event, file)"
          class="file-item"
          :class="{ 'is-directory': file.type === 'directory' }"
        >
          <div class="file-icon">
            <i :class="getFileIcon(file)"></i>
          </div>
          <div class="file-info">
            <div class="file-name">{{ file.name }}</div>
            <div class="file-details">
              <span v-if="file.type === 'file'" class="file-size">
                {{ formatFileSize(file.size) }}
              </span>
              <span class="file-permissions">{{ file.permissions }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Upload Modal -->
    <FileUpload 
      v-if="showUpload"
      :container-id="containerId"
      :current-path="currentPath"
      @close="showUpload = false"
      @uploaded="onFileUploaded"
    />

    <!-- Create Folder Modal -->
    <div v-if="showCreateFolder" class="modal">
      <div class="modal-content">
        <div class="modal-header">
          <h3>Create New Folder</h3>
          <button @click="showCreateFolder = false" class="close-btn">×</button>
        </div>
        <div class="modal-body">
          <form @submit.prevent="createFolder">
            <div class="form-group">
              <label>Folder Name:</label>
              <input 
                v-model="newFolderName" 
                type="text" 
                required 
                class="form-control"
                placeholder="Enter folder name"
              >
            </div>
            <div class="form-group">
              <label>Permissions:</label>
              <input 
                v-model="newFolderPermissions" 
                type="text" 
                pattern="[0-7]{3,4}"
                class="form-control"
                placeholder="755"
              >
            </div>
            <div class="form-actions">
              <button type="submit" class="btn btn-primary">Create</button>
              <button type="button" @click="showCreateFolder = false" class="btn btn-secondary">
                Cancel
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Context Menu -->
    <div 
      v-if="contextMenu.show" 
      :style="{ top: contextMenu.y + 'px', left: contextMenu.x + 'px' }"
      class="context-menu"
    >
      <button v-if="contextMenu.file.type === 'file'" @click="downloadFile(contextMenu.file)">
        <i class="fas fa-download"></i> Download
      </button>
      <button @click="showPermissionsModal(contextMenu.file)">
        <i class="fas fa-key"></i> Permissions
      </button>
      <button @click="deleteItem(contextMenu.file)" class="danger">
        <i class="fas fa-trash"></i> Delete
      </button>
    </div>

    <!-- Permissions Modal -->
    <FilePermissions 
      v-if="showPermissions"
      :file="selectedFile"
      :container-id="containerId"
      @close="showPermissions = false"
      @updated="onPermissionsUpdated"
    />
  </div>
</template>

<script>
import FileUpload from './FileUpload.vue';
import FilePermissions from './FilePermissions.vue';

export default {
  name: 'FileBrowser',
  components: {
    FileUpload,
    FilePermissions
  },
  props: {
    containerId: {
      type: String,
      required: true
    }
  },
  data() {
    return {
      files: [],
      mounts: [],
      currentPath: '/',
      loading: false,
      showUpload: false,
      showCreateFolder: false,
      showPermissions: false,
      newFolderName: '',
      newFolderPermissions: '755',
      selectedFile: null,
      contextMenu: {
        show: false,
        x: 0,
        y: 0,
        file: null
      }
    };
  },
  computed: {
    pathSegments() {
      return this.currentPath.split('/').filter(segment => segment);
    }
  },
  async mounted() {
    await this.loadMounts();
    await this.loadFiles();
    
    // Close context menu on click outside
    document.addEventListener('click', this.closeContextMenu);
  },
  beforeUnmount() {
    document.removeEventListener('click', this.closeContextMenu);
  },
  methods: {
    async loadFiles() {
      this.loading = true;
      try {
        const response = await fetch(`/api/containers/${this.containerId}/files?path=${encodeURIComponent(this.currentPath)}`, {
          headers: {
            'Authorization': `Bearer ${this.getToken()}`,
            'Accept': 'application/json'
          }
        });
        
        const data = await response.json();
        if (data.success) {
          this.files = data.data;
        } else {
          this.showError(data.message);
        }
      } catch (error) {
        this.showError('Failed to load files');
      } finally {
        this.loading = false;
      }
    },

    async loadMounts() {
      try {
        const response = await fetch(`/api/containers/${this.containerId}/files/mounts`, {
          headers: {
            'Authorization': `Bearer ${this.getToken()}`,
            'Accept': 'application/json'
          }
        });
        
        const data = await response.json();
        if (data.success) {
          this.mounts = data.data;
        }
      } catch (error) {
        console.error('Failed to load mounts:', error);
      }
    },

    async navigateToPath(path) {
      this.currentPath = path;
      await this.loadFiles();
    },

    getPathUpTo(index) {
      return '/' + this.pathSegments.slice(0, index + 1).join('/');
    },

    handleFileClick(file) {
      if (file.type === 'directory') {
        this.navigateToPath(file.path);
      }
    },

    async refreshFiles() {
      await this.loadFiles();
    },

    async createFolder() {
      try {
        const response = await fetch(`/api/containers/${this.containerId}/files/directories`, {
          method: 'POST',
          headers: {
            'Authorization': `Bearer ${this.getToken()}`,
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            path: `${this.currentPath}/${this.newFolderName}`,
            permissions: this.newFolderPermissions
          })
        });

        const data = await response.json();
        if (data.success) {
          this.showCreateFolder = false;
          this.newFolderName = '';
          this.newFolderPermissions = '755';
          await this.loadFiles();
          this.showSuccess('Folder created successfully');
        } else {
          this.showError(data.message);
        }
      } catch (error) {
        this.showError('Failed to create folder');
      }
    },

    async downloadFile(file) {
      try {
        const response = await fetch(`/api/containers/${this.containerId}/files/download?path=${encodeURIComponent(file.path)}`, {
          headers: {
            'Authorization': `Bearer ${this.getToken()}`
          }
        });

        if (response.ok) {
          const blob = await response.blob();
          const url = window.URL.createObjectURL(blob);
          const a = document.createElement('a');
          a.href = url;
          a.download = file.name;
          document.body.appendChild(a);
          a.click();
          window.URL.revokeObjectURL(url);
          document.body.removeChild(a);
          this.showSuccess('File downloaded successfully');
        } else {
          this.showError('Failed to download file');
        }
      } catch (error) {
        this.showError('Failed to download file');
      }
      this.closeContextMenu();
    },

    async deleteItem(file) {
      if (!confirm(`Are you sure you want to delete ${file.name}?`)) {
        return;
      }

      try {
        const response = await fetch(`/api/containers/${this.containerId}/files`, {
          method: 'DELETE',
          headers: {
            'Authorization': `Bearer ${this.getToken()}`,
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            path: file.path,
            is_directory: file.type === 'directory'
          })
        });

        const data = await response.json();
        if (data.success) {
          await this.loadFiles();
          this.showSuccess(data.message);
        } else {
          this.showError(data.message);
        }
      } catch (error) {
        this.showError('Failed to delete item');
      }
      this.closeContextMenu();
    },

    showContextMenu(event, file) {
      this.contextMenu = {
        show: true,
        x: event.clientX,
        y: event.clientY,
        file: file
      };
    },

    closeContextMenu() {
      this.contextMenu.show = false;
    },

    showPermissionsModal(file) {
      this.selectedFile = file;
      this.showPermissions = true;
      this.closeContextMenu();
    },

    onFileUploaded() {
      this.loadFiles();
      this.showSuccess('File uploaded successfully');
    },

    onPermissionsUpdated() {
      this.loadFiles();
      this.showSuccess('Permissions updated successfully');
    },

    getFileIcon(file) {
      if (file.type === 'directory') {
        return 'fas fa-folder';
      }

      const extension = file.name.split('.').pop().toLowerCase();
      const iconMap = {
        'txt': 'fas fa-file-text',
        'pdf': 'fas fa-file-pdf',
        'jpg': 'fas fa-file-image',
        'jpeg': 'fas fa-file-image',
        'png': 'fas fa-file-image',
        'gif': 'fas fa-file-image',
        'zip': 'fas fa-file-archive',
        'tar': 'fas fa-file-archive',
        'gz': 'fas fa-file-archive',
        'js': 'fas fa-file-code',
        'php': 'fas fa-file-code',
        'html': 'fas fa-file-code',
        'css': 'fas fa-file-code',
        'json': 'fas fa-file-code',
        'xml': 'fas fa-file-code',
        'yml': 'fas fa-file-code',
        'yaml': 'fas fa-file-code'
      };

      return iconMap[extension] || 'fas fa-file';
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

    showSuccess(message) {
      // Implement your success notification system
      console.log('Success:', message);
    },

    showError(message) {
      // Implement your error notification system
      console.error('Error:', message);
    }
  }
};
</script>

<style scoped>
.file-browser {
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
  overflow: hidden;
}

.browser-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
}

.breadcrumb {
  display: flex;
  align-items: center;
}

.breadcrumb-item {
  background: none;
  border: none;
  color: #007bff;
  cursor: pointer;
  padding: 0.25rem 0.5rem;
  border-radius: 4px;
  margin-right: 0.5rem;
}

.breadcrumb-item:hover {
  background: #e9ecef;
}

.breadcrumb-item::after {
  content: '/';
  color: #6c757d;
  margin-left: 0.5rem;
}

.breadcrumb-item:last-child::after {
  display: none;
}

.header-actions {
  display: flex;
  gap: 0.5rem;
}

.mounted-volumes {
  padding: 1rem;
  background: #f8f9fa;
  border-bottom: 1px solid #dee2e6;
}

.volume-list {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-top: 0.5rem;
}

.volume-item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 1rem;
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.2s;
}

.volume-item:hover {
  background: #e9ecef;
}

.volume-type {
  font-size: 0.75rem;
  color: #6c757d;
  background: #e9ecef;
  padding: 0.25rem 0.5rem;
  border-radius: 12px;
}

.file-list {
  padding: 1rem;
}

.loading, .empty-state {
  text-align: center;
  padding: 2rem;
  color: #6c757d;
}

.file-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 1rem;
}

.file-item {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem;
  border: 1px solid #dee2e6;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.2s;
}

.file-item:hover {
  background: #f8f9fa;
  border-color: #007bff;
}

.file-item.is-directory {
  background: #e3f2fd;
}

.file-icon {
  font-size: 1.5rem;
  color: #6c757d;
}

.file-item.is-directory .file-icon {
  color: #007bff;
}

.file-info {
  flex: 1;
  min-width: 0;
}

.file-name {
  font-weight: 500;
  margin-bottom: 0.25rem;
  word-break: break-word;
}

.file-details {
  font-size: 0.75rem;
  color: #6c757d;
  display: flex;
  gap: 0.5rem;
}

.context-menu {
  position: fixed;
  background: #fff;
  border: 1px solid #dee2e6;
  border-radius: 4px;
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
  z-index: 1000;
  min-width: 150px;
}

.context-menu button {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
  padding: 0.5rem 1rem;
  border: none;
  background: none;
  text-align: left;
  cursor: pointer;
}

.context-menu button:hover {
  background: #f8f9fa;
}

.context-menu button.danger {
  color: #dc3545;
}

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
  max-width: 500px;
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
  margin-top: 1rem;
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

.btn-secondary:hover {
  background: #545b62;
  border-color: #545b62;
}

.btn-outline {
  background: transparent;
  color: #007bff;
  border-color: #007bff;
}

.btn-outline:hover {
  background: #007bff;
  color: #fff;
}
</style>