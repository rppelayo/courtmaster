async function login(email, password) {
    const formData = new FormData();
    formData.append("action", "login");
    formData.append("email", email);
    formData.append("password", password);
  
    const response = await fetch("/api/auth.php", {
      method: "POST",
      body: formData
    });
  
    const result = await response.json();
    if (result.success) {
      if (result.role === 'admin' || result.role === 'owner') {
        window.location.href = "admin_dashboard.php";
      } else {
        window.location.href = "dashboard.php";
      }
    } else {
      alert(result.error);
    }
  }
  