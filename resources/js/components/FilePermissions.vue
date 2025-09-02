<template>
  <div class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3>File Permissions - {{ file.name }}</h3>
        <button @click="$emit('close')" class="close-btn">×</button>
      </div>
      <div class="modal-body">
        <div class="current-permissions">
          <h4>Current Permissions</h4>
          <div class="permission-display">
            <span class="permission-octal">{{ file.permissions }}</span>
            <span class="permission-text">{{ getPermissionText(file.permissions) }}</span>
          </div>
        </div>

        <form @submit.prevent="updatePermissions">
          <div class="permission-editor">
            <h4>Set New Permissions</h4>
            
            <!-- Octal Input -->
            <div class="form-group">
              <label>Octal Notation:</label>
              <input 
                v-model="newPermissions" 
                type="text" 
                pattern="[0-7]{3,4}"
                class="form-control"
                placeholder="644"
                @input="updateFromOctal"
              >
            </div>

            <!-- Visual Permission Editor -->
            <div class="permission-grid">
              <div class="permission-group">
                <h5>Owner</h5>
                <div class="permission-checkboxes">
                  <label>
                    <input 
                      type="checkbox" 
                      v-model="permissions.owner.read"
                      @change="updateFromCheckboxes"
                    > Read
                  </label>
                  <label>
                    <input 
                      type="checkbox" 
                      v-model="permissions.owner.write"
                      @change="updateFromCheckboxes"
                    > Write
                  </label>
                  <label>
                    <input 
                      type="checkbox" 
                      v-model="permissions.owner.execute"
                      @change="updateFromCheckboxes"
                    > Execute
                  </label>
                </div>
              </div>

              <div class="permission-group">
                <h5>Group</h5>
                <div class="permission-checkboxes">
                  <label>
                    <input 
                      type="checkbox" 
                      v-model="permissions.group.read"
                      @change="updateFromCheckboxes"
                    > Read
                  </label>
                  <label>
                    <input 
                      type="checkbox" 
                      v-model="permissions.group.write"
                      @change="updateFromCheckboxes"
                    > Write
                  </label>
                  <label>
                    <input 
                      type="checkbox" 
                      v-model="permissions.group.execute"
                      @change="updateFromCheckboxes"
                    > Execute
                  </label>
                </div>
              </div>

              <div class="permission-group">
                <h5>Others</h5>
                <div class="permission-checkboxes">
                  <label>
                    <input 
                      type="checkbox" 
                      v-model="permissions.others.read"
                      @change="updateFromCheckboxes"
                    > Read
                  </label>
                  <label>
                    <input 
                      type="checkbox" 
                      v-model="permissions.others.write"
                      @change="updateFromCheckboxes"
                    > Write
                  </label>
                  <label>
                    <input 
                      type="checkbox" 
                      v-model="permissions.others.execute"
                      @change="updateFromCheckboxes"
                    > Execute
                  </label>
                </div>
              </div>
            </div>

            <!-- Recursive Option for Directories -->
            <div v-if="file.type === 'directory'" class="form-group">
              <label>
                <input type="checkbox" v-model="recursive"> 
                Apply recursively to all files and subdirectories
              </label>
            </div>

            <!-- Common Permission Presets -->
            <div class="permission-presets">
              <h5>Common Permissions</h5>
              <div class="preset-buttons">
                <button type="button" @click="setPreset('644')" class="preset-btn">
                  644 (rw-r--r--)
                </button>
                <button type="button" @click="setPreset('755')" class="preset-btn">
                  755 (rwxr-xr-x)
                </button>
                <button type="button" @click="setPreset('600')" class="preset-btn">
                  600 (rw-------)
                </button>
                <button type="button" @click="setPreset('700')" class="preset-btn">
                  700 (rwx------)
                </button>
              </div>
            </div>
          </div>

          <div class="form-actions">
            <button type="submit" class="btn btn-primary" :disabled="updating">
              <i v-if="updating" class="fas fa-spinner fa-spin"></i>
              {{ updating ? 'Updating...' : 'Update Permissions' }}
            </button>
            <button type="button" @click="$emit('close')" class="btn btn-secondary">
              Cancel
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'FilePermissions',
  props: {
    file: {
      type: Object,
      required: true
    },
    containerId: {
      type: String,
      required: true
    }
  },
  emits: ['close', 'updated'],
  data() {
    return {
      newPermissions: '',
      recursive: false,
      updating: false,
      permissions: {
        owner: { read: false, write: false, execute: false },
        group: { read: false, write: false, execute: false },
        others: { read: false, write: false, execute: false }
      }
    };
  },
  mounted() {
    this.initializePermissions();
  },
  methods: {
    initializePermissions() {
      // Extract octal permissions from file.permissions (e.g., "rwxr-xr-x" -> "755")
      const perms = this.file.permissions;
      if (perms && perms.length >= 9) {
        this.newPermissions = this.parsePermissionsToOctal(perms);
        this.updateFromOctal();
      }
    },

    parsePermissionsToOctal(permString) {
      // Convert "rwxr-xr-x" to "755"
      let octal = '';
      
      // Process in groups of 3 (owner, group, others)
      for (let i = 1; i < permString.length; i += 3) {
        let value = 0;
        if (permString[i] === 'r') value += 4;
        if (permString[i + 1] === 'w') value += 2;
        if (permString[i + 2] === 'x') value += 1;
        octal += value;
      }
      
      return octal;
    },

    updateFromOctal() {
      const octal = this.newPermissions;
      if (!/^[0-7]{3,4}$/.test(octal)) return;

      const digits = octal.slice(-3); // Take last 3 digits
      
      // Owner permissions
      const owner = parseInt(digits[0]);
      this.permissions.owner.read = (owner & 4) !== 0;
      this.permissions.owner.write = (owner & 2) !== 0;
      this.permissions.owner.execute = (owner & 1) !== 0;

      // Group permissions
      const group = parseInt(digits[1]);
      this.permissions.group.read = (group & 4) !== 0;
      this.permissions.group.write = (group & 2) !== 0;
      this.permissions.group.execute = (group & 1) !== 0;

      // Others permissions
      const others = parseInt(digits[2]);
      this.permissions.others.read = (others & 4) !== 0;
      this.permissions.others.write = (others & 2) !== 0;
      this.permissions.others.execute = (others & 1) !== 0;
    },

    updateFromCheckboxes() {
      let octal = '';

      // Calculate owner permissions
      let owner = 0;
      if (this.permissions.owner.read) owner += 4;
      if (this.permissions.owner.write) owner += 2;
      if (this.permissions.owner.execute) owner += 1;
      octal += owner;

      // Calculate group permissions
      let group = 0;
      if (this.permissions.group.read) group += 4;
      if (this.permissions.group.write) group += 2;
      if (this.permissions.group.execute) group += 1;
      octal += group;

      // Calculate others permissions
      let others = 0;
      if (this.permissions.others.read) others += 4;
      if (this.permissions.others.write) others += 2;
      if (this.permissions.others.execute) others += 1;
      octal += others;

      this.newPermissions = octal;
    },

    setPreset(preset) {
      this.newPermissions = preset;
      this.updateFromOctal();
    },

    async updatePermissions() {
      if (!/^[0-7]{3,4}$/.test(this.newPermissions)) {
        this.showError('Invalid permissions format');
        return;
      }

      this.updating = true;

      try {
        const response = await fetch(`/api/containers/${this.containerId}/files/permissions`, {
          method: 'PUT',
          headers: {
            'Authorization': `Bearer ${this.getToken()}`,
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            path: this.file.path,
            permissions: this.newPermissions,
            recursive: this.recursive
          })
        });

        const data = await response.json();
        if (data.success) {
          this.$emit('updated');
          this.$emit('close');
        } else {
          this.showError(data.message);
        }
      } catch (error) {
        this.showError('Failed to update permissions');
      } finally {
        this.updating = false;
      }
    },

    getPermissionText(permString) {
      if (!permString || permString.length < 10) return 'Unknown';
      return permString.substring(1); // Remove the first character (file type)
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

.current-permissions {
  margin-bottom: 2rem;
  padding: 1rem;
  background: #f8f9fa;
  border-radius: 8px;
}

.permission-display {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-top: 0.5rem;
}

.permission-octal {
  font-family: 'Courier New', monospace;
  font-size: 1.2rem;
  font-weight: bold;
  color: #007bff;
}

.permission-text {
  font-family: 'Courier New', monospace;
  color: #6c757d;
}

.permission-editor {
  margin-bottom: 2rem;
}

.permission-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1rem;
  margin-bottom: 1rem;
}

.permission-group {
  border: 1px solid #dee2e6;
  border-radius: 8px;
  padding: 1rem;
}

.permission-group h5 {
  margin: 0 0 1rem 0;
  color: #495057;
  text-align: center;
}

.permission-checkboxes {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.permission-checkboxes label {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  cursor: pointer;
}

.permission-presets {
  margin-top: 2rem;
}

.permission-presets h5 {
  margin-bottom: 1rem;
}

.preset-buttons {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 0.5rem;
}

.preset-btn {
  padding: 0.5rem 1rem;
  border: 1px solid #dee2e6;
  background: #fff;
  border-radius: 4px;
  cursor: pointer;
  font-family: 'Courier New', monospace;
  transition: all 0.2s;
}

.preset-btn:hover {
  background: #f8f9fa;
  border-color: #007bff;
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
  font-family: 'Courier New', monospace;
}

.form-actions {
  display: flex;
  gap: 0.5rem;
  justify-content: flex-end;
  border-top: 1px solid #dee2e6;
  padding-top: 1rem;
}

.btn {
  padding: 0.5rem 1rem;
  border: 1px solid transparent;
  border-radius: 4px;
  cursor: pointer;
  font-size: 0.875rem;
  transition: all 0.2s;
}

.btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.btn-primary {
  background: #007bff;
  color: #fff;
  border-color: #007bff;
}

.btn-primary:hover:not(:disabled) {
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
</style>